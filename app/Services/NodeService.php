<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NodeStatusChanged;
use App\Models\EnrollToken;
use App\Models\Node;
use App\Models\NodeCredential;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\SignatureVerifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NodeService
{
    /**
     * Exchange an enrollment token for node credentials.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{node: Node, secret: string}
     */
    public function enroll(EnrollToken $token, array $attributes, ?string $ip = null): array
    {
        return DB::transaction(function () use ($token, $attributes, $ip): array {
            [$plain, $hash, $cipher] = SignatureVerifier::issueSecret();

            $node = Node::create([
                'name' => $attributes['name'] ?? 'node-' . strtolower(Str::random(6)),
                'node_id' => Node::generateNodeId(),
                'secret_hash' => $hash,
                'secret_ciphertext' => $cipher,
                'status' => Node::STATUS_ONLINE,
                'ip' => $attributes['ip'] ?? $ip,
                'region' => $attributes['region'] ?? $token->region_hint,
                'tags' => $attributes['tags'] ?? $token->tags_hint ?? [],
                'version' => $attributes['version'] ?? null,
                'agent_version' => $attributes['agent_version'] ?? null,
                'meta' => [
                    'arch' => $attributes['arch'] ?? null,
                    'kernel' => $attributes['kernel'] ?? null,
                    'enrolled_via' => 'api',
                ],
                'enrolled_ip' => $ip,
                'enrolled_at' => now(),
                'last_seen_at' => now(),
            ]);

            $node->credentials()->create([
                'secret_hash' => $hash,
                'secret_ciphertext' => $cipher,
                'label' => 'initial',
                'rotated_at' => now(),
            ]);

            $token->consume();

            AuditLogger::log('node.enrolled', $node, [
                'node_id' => $node->node_id,
                'token_prefix' => $token->prefix,
                'ip' => $ip,
            ], 'agent', $ip);

            return ['node' => $node, 'secret' => $plain];
        });
    }

    /**
     * Record liveness + reported attributes.
     *
     * Scale note: at 5000 nodes a naive implementation writes on every request.
     * The timestamp refresh is therefore gated to one UPDATE per node per
     * self::SEEN_WRITE_INTERVAL, while any *semantic* change (status, version,
     * region, tags) is always persisted immediately.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markSeen(Node $node, array $attributes = []): Node
    {
        $previous = $node->status;

        $node->fill(array_filter([
            'ip' => $attributes['ip'] ?? null,
            'version' => $attributes['version'] ?? null,
            'agent_version' => $attributes['agent_version'] ?? null,
        ], static fn ($value) => $value !== null));

        if (isset($attributes['region'])) {
            $node->region = (string) $attributes['region'];
        }

        if (isset($attributes['tags']) && is_array($attributes['tags'])) {
            $node->tags = $attributes['tags'];
        }

        if (isset($attributes['meta']) && is_array($attributes['meta'])) {
            $node->meta = array_merge($node->meta ?? [], $attributes['meta']);
        }

        if ($node->status !== Node::STATUS_ONLINE) {
            $node->status = Node::STATUS_ONLINE;
        }

        if ($node->enrolled_at === null) {
            $node->enrolled_at = now();
        }

        $node->last_seen_at = now();

        $statusChanged = $previous !== $node->status;
        $meaningfulChange = $statusChanged
            || $node->isDirty(['ip', 'version', 'agent_version', 'region', 'tags', 'meta', 'enrolled_at'])
            || $node->getRawOriginal('last_seen_at') === null;

        if (! $meaningfulChange && ! self::heartbeatGateOpen($node)) {
            // Nothing to persist: keep the in-memory timestamp and skip the write.
            return $node;
        }

        $node->save();

        if ($statusChanged) {
            NodeStatusChanged::dispatch($node, $previous, $node->status);
        }

        return $node;
    }

    /** One write per node per interval is enough for a 90s offline threshold. */
    public const SEEN_WRITE_INTERVAL = 15;

    public static function heartbeatGateOpen(Node $node): bool
    {
        return Cache::add('node:heartbeat:' . $node->id, 1, self::SEEN_WRITE_INTERVAL);
    }

    /**
     * Unconditional liveness refresh, used for infrequent but load bearing
     * reports (a metric batch). Batches arrive at most a few times per minute
     * per node, so an un-gated write is affordable there.
     */
    public function forceLiveness(Node $node): Node
    {
        $previous = $node->status;

        $node->status = Node::STATUS_ONLINE;
        $node->last_seen_at = now();

        if ($node->enrolled_at === null) {
            $node->enrolled_at = now();
        }

        $node->save();

        if ($previous !== Node::STATUS_ONLINE) {
            NodeStatusChanged::dispatch($node, $previous, $node->status);
        }

        return $node;
    }

    public function setStatus(Node $node, string $status): Node
    {
        $previous = $node->status;

        if ($previous === $status) {
            return $node;
        }

        $node->status = $status;
        $node->save();

        NodeStatusChanged::dispatch($node, $previous, $status);

        AuditLogger::log('node.status_changed', $node, ['from' => $previous, 'to' => $status]);

        return $node;
    }

    public function markOffline(Node $node): Node
    {
        return $this->setStatus($node, Node::STATUS_OFFLINE);
    }

    public function rotateSecret(Node $node, ?User $actor = null): string
    {
        $plain = $node->rotateSecret();

        AuditLogger::log('node.secret_rotated', $node, ['node_id' => $node->node_id], $actor?->name);

        return $plain;
    }

    /** @return array<string, int|float> */
    public function stats(): array
    {
        $threshold = now()->subSeconds((int) \App\Support\Setting::get('offline_threshold', 90));

        return [
            'total' => Node::query()->count(),
            'online' => Node::query()->where('status', Node::STATUS_ONLINE)
                ->where('last_seen_at', '>=', $threshold)->count(),
            'offline' => Node::query()->where(static function ($query) use ($threshold): void {
                $query->whereIn('status', [Node::STATUS_OFFLINE, Node::STATUS_PENDING])
                    ->orWhere('last_seen_at', '<', $threshold)
                    ->orWhereNull('last_seen_at');
            })->count(),
            'error' => Node::query()->where('status', Node::STATUS_ERROR)->count(),
            'disabled' => Node::query()->where('status', Node::STATUS_DISABLED)->count(),
            'users' => (int) \App\Models\NodeMetric::query()->where('recorded_at', '>=', now()->subMinutes(10))->sum('users'),
        ];
    }
}
