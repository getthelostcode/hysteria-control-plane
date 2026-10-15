<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConfigValidationException;
use App\Events\ConfigPublished;
use App\Jobs\PushConfigToNode;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Models\NodeConfigHistory;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class ConfigService
{
    public function latest(Node $node): ?NodeConfig
    {
        return NodeConfig::query()
            ->where('node_id', $node->id)
            ->orderByDesc('version')
            ->first();
    }

    /** @param array<string, mixed> $meta */
    public function publish(
        Node $node,
        string $yaml,
        ?User $actor = null,
        string $reason = '',
        array $meta = [],
    ): NodeConfig {
        $this->assertValidYaml($yaml);
        $meta = array_merge(['origin' => 'panel'], $meta);

        return DB::transaction(function () use ($node, $yaml, $actor, $reason, $meta): NodeConfig {
            $previous = $this->latest($node);
            $version = ($previous?->version ?? 0) + 1;

            $config = NodeConfig::create([
                'node_id' => $node->id,
                'version' => $version,
                'content_yaml' => $yaml,
                'sha256' => hash('sha256', $yaml),
                'status' => NodeConfig::STATUS_PUBLISHED,
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);

            NodeConfigHistory::create([
                'node_id' => $node->id,
                'from_version' => $previous?->version,
                'to_version' => $version,
                'action' => NodeConfigHistory::ACTION_PUBLISH,
                'operator' => $actor?->name ?? 'system',
                'reason' => $reason,
                'meta' => $meta,
            ]);

            AuditLogger::log('config.published', $config, [
                'node_id' => $node->node_id,
                'version' => $version,
                'sha256' => $config->sha256,
                'reason' => $reason,
            ], $actor?->name);

            PushConfigToNode::dispatch($config->id);
            ConfigPublished::dispatch($node->node_id, $config->only(['version', 'sha256', 'status']));

            return $config;
        });
    }

    /** @param array<string, mixed> $meta */
    public function acknowledge(Node $node, int $version, string $status, ?string $error = null, array $meta = []): NodeConfig
    {
        $config = NodeConfig::query()->where('node_id', $node->id)->where('version', $version)->firstOrFail();

        $config->status = in_array($status, [NodeConfig::STATUS_APPLIED, 'applied'], true)
            ? NodeConfig::STATUS_APPLIED
            : NodeConfig::STATUS_FAILED;
        $config->error = $error;
        $config->applied_at = now();
        $config->save();

        NodeConfigHistory::create([
            'node_id' => $node->id,
            'from_version' => $version,
            'to_version' => $version,
            'action' => NodeConfigHistory::ACTION_ACK,
            'operator' => 'agent',
            'reason' => $error,
            'meta' => $meta,
        ]);

        AuditLogger::log('config.acknowledged', $config, [
            'node_id' => $node->node_id,
            'version' => $version,
            'status' => $config->status,
            'error' => $error,
        ], 'agent');

        if ($config->status === NodeConfig::STATUS_FAILED) {
            $fallback = NodeConfig::query()
                ->where('node_id', $node->id)
                ->where('version', '<', $version)
                ->where('status', NodeConfig::STATUS_APPLIED)
                ->orderByDesc('version')
                ->first();

            if ($fallback !== null) {
                // Acceptance criterion: a failed apply must roll the node back automatically.
                $this->rollback($node, $fallback->version, null, 'auto rollback: version ' . $version . ' failed to apply');
                $node->refresh();
                $node->status = Node::STATUS_ERROR;
                $node->save();
            }
        }

        return $config->refresh();
    }

    public function rollback(Node $node, int $version, ?User $actor, string $reason = ''): NodeConfig
    {
        $config = NodeConfig::query()->where('node_id', $node->id)->where('version', $version)->firstOrFail();

        return DB::transaction(function () use ($node, $config, $actor, $reason): NodeConfig {
            $current = $this->latest($node);
            $version = ($current?->version ?? 0) + 1;

            $snapshot = NodeConfig::create([
                'node_id' => $node->id,
                'version' => $version,
                'content_yaml' => $config->content_yaml,
                'sha256' => $config->sha256,
                'status' => NodeConfig::STATUS_PUBLISHED,
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);

            NodeConfigHistory::create([
                'node_id' => $node->id,
                'from_version' => $current?->version,
                'to_version' => $version,
                'action' => NodeConfigHistory::ACTION_ROLLBACK,
                'operator' => $actor?->name ?? 'system',
                'reason' => $reason,
                'meta' => ['rolled_back_to' => $config->version],
            ]);

            AuditLogger::log('config.rolled_back', $snapshot, [
                'node_id' => $node->node_id,
                'from_version' => $current?->version,
                'to_version' => $version,
                'rolled_back_to' => $config->version,
                'reason' => $reason,
            ], $actor?->name);

            PushConfigToNode::dispatch($snapshot->id);
            ConfigPublished::dispatch($node->node_id, $snapshot->only(['version', 'sha256', 'status']));

            return $snapshot;
        });
    }

    public function assertValidYaml(string $yaml): void
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new ConfigValidationException('Invalid YAML: ' . $exception->getMessage(), 0, $exception);
        }

        if (! is_array($parsed) || $parsed === []) {
            throw new ConfigValidationException('Config must be a non-empty YAML mapping.');
        }
    }
}
