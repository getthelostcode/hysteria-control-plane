<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NodeStatusChanged;
use App\Models\EnrollToken;
use App\Models\Node;
use App\Models\NodeCredential;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\PublicIp;
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
        // 节点要展示的地址：优先 agent 自报的公网 IP（NAT/桥接下唯一可靠来源），
        // 其次是从代理链推导出的客户端地址，最后才是观测到的对端地址。
        $public = PublicIp::resolve($attributes['public_ip'] ?? $attributes['ip'] ?? null, $ip);

        return DB::transaction(function () use ($token, $attributes, $ip, $public): array {
            [$plain, $hash, $cipher] = SignatureVerifier::issueSecret();

            $node = Node::create([
                'name' => $attributes['name'] ?? 'node-' . strtolower(Str::random(6)),
                'node_id' => Node::generateNodeId(),
                'secret_hash' => $hash,
                'secret_ciphertext' => $cipher,
                'status' => Node::STATUS_ONLINE,
                'ip' => $public['ip'],
                'region' => $attributes['region'] ?? $token->region_hint,
                'tags' => $attributes['tags'] ?? $token->tags_hint ?? [],
                'version' => $attributes['version'] ?? null,
                'agent_version' => $attributes['agent_version'] ?? null,
                'meta' => [
                    'arch' => $attributes['arch'] ?? null,
                    'kernel' => $attributes['kernel'] ?? null,
                    'enrolled_via' => 'api',
                    // 地址来源：agent=节点自报公网 IP / proxy=代理链推导 / peer=只看到对端
                    'ip_source' => $public['source'],
                    // 只展示 IPv4，但 IPv6 出口也留档（排障/防火墙用得上）
                    'ip_v6' => $public['ip_v6'],
                ],
                // 观测到的对端地址单独留档：它可能是 cloudflared 的 127.0.0.1，
                // 排障时能区分「节点换了 IP」和「请求没经过代理链」。
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
                'ip' => $node->ip,
                'ip_source' => $public['source'],
                'observed_ip' => $ip,
            ], 'agent', (string) $ip);

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
            'version' => $attributes['version'] ?? null,
            'agent_version' => $attributes['agent_version'] ?? null,
        ], static fn ($value) => $value !== null));

        // 地址：agent 自报（心跳里的 public_ip/ip）优先于代理链推导（中间件传 observed_ip），
        // 且 **IPv4 优先**；私网/回环地址（内网部署常见）不得覆盖已有的公网地址。
        $reportedIp = $attributes['public_ip'] ?? $attributes['ip'] ?? null;
        $observedIp = $attributes['observed_ip'] ?? null;

        if ($reportedIp !== null || $observedIp !== null) {
            $public = PublicIp::resolve($reportedIp, $observedIp);

            // 已有 IPv4 不被新的 IPv6 顶掉：面板按 IPv4 展示，双栈节点在 v4/v6 出口之间
            // 来回切换时列表不应跳变。确实是 v4 没了（节点搬走），运维可在面板手工修正。
            if (! PublicIp::isIpv4($public['ip']) && PublicIp::isIpv4($node->ip)) {
                $public['ip'] = $node->ip;
                $public['source'] = (string) ($node->meta['ip_source'] ?? 'peer');
                $public['ip_v6'] = $public['ip_v6'] ?? $reportedIp;
            }

            if ($public['ip'] !== null) {
                $node->ip = $public['ip'];
                $node->meta = array_merge($node->meta ?? [], array_filter([
                    'ip_source' => $public['source'],
                    'ip_v6' => $public['ip_v6'],
                ], static fn ($value) => $value !== null));
            }
        }

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
