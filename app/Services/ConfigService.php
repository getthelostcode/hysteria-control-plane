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
    /**
     * 「默认下发」的版本：优先 is_current（运维可指定任意版本），没有标记时退回最大版本号。
     *
     * 顺序很关键：面板/API/agent 的 /config/latest 都走这里，改错就会出现「明明指定了 v3，
     * 节点却拿到 v7」。is_current 是唯一权威，版本号只在没标记时兜底（老数据）。
     */
    public function latest(Node $node): ?NodeConfig
    {
        return NodeConfig::query()
            ->where('node_id', $node->id)
            ->orderByDesc('is_current')
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
        bool $makeCurrent = true,
    ): NodeConfig {
        $this->assertValidYaml($yaml);
        $meta = array_merge(['origin' => 'panel'], $meta);

        return DB::transaction(function () use ($node, $yaml, $actor, $reason, $meta, $makeCurrent): NodeConfig {
            $previous = $this->latest($node);
            $version = ($previous?->version ?? 0) + 1;

            $config = NodeConfig::create([
                'node_id' => $node->id,
                'version' => $version,
                'content_yaml' => $yaml,
                'sha256' => hash('sha256', $yaml),
                'status' => NodeConfig::STATUS_PUBLISHED,
                // 新版本默认就成为「默认下发」那一版；勾掉则只入历史（草稿），由运维显式指定
                'is_current' => $makeCurrent,
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);

            if ($makeCurrent) {
                // 一台节点只能有一个「默认下发」版本
                NodeConfig::query()
                    ->where('node_id', $node->id)
                    ->whereKeyNot($config->getKey())
                    ->update(['is_current' => false]);
            }

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
                'is_current' => $makeCurrent,
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
                'is_current' => true,
                'published_by' => $actor?->id,
                'published_at' => now(),
            ]);

            // 回滚出来的版本就是「现在要下发的那一版」——漏了这句，latest() 还会指回失败的那版
            NodeConfig::query()
                ->where('node_id', $node->id)
                ->whereKeyNot($snapshot->getKey())
                ->update(['is_current' => false]);

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

    /**
     * 指定「默认下发」的版本（运维说「就下这一版」）。
     *
     * 与 rollback() 的区别：rollback 会**以旧正文生成一个新版本**（历史是追加的），
     * 这里只是把指针挪到已有版本上 —— 不改任何正文，节点下次同步就会取走它。
     */
    public function setCurrent(Node $node, int $version, ?User $actor = null, string $reason = ''): NodeConfig
    {
        return DB::transaction(function () use ($node, $version, $actor, $reason): NodeConfig {
            $config = NodeConfig::query()
                ->where('node_id', $node->id)
                ->where('version', $version)
                ->firstOrFail();

            $previous = $this->latest($node);

            NodeConfig::query()
                ->where('node_id', $node->id)
                ->whereKeyNot($config->getKey())
                ->update(['is_current' => false]);

            $config->forceFill(['is_current' => true])->save();

            NodeConfigHistory::create([
                'node_id' => $node->id,
                'from_version' => $previous?->version,
                'to_version' => $config->version,
                'action' => NodeConfigHistory::ACTION_ACTIVATE,
                'operator' => $actor?->name ?? 'system',
                'reason' => $reason,
                'meta' => ['sha256' => $config->sha256],
            ]);

            AuditLogger::log('config.activated', $config, [
                'node_id' => $node->node_id,
                'version' => $config->version,
                'sha256' => $config->sha256,
                'reason' => $reason,
            ], $actor?->name);

            ConfigPublished::dispatch($node->node_id, $config->only(['version', 'sha256', 'status']));

            return $config;
        });
    }

    /**
     * 就地修改某个版本的正文（含 sha256 重算）。
     *
     * 节点侧是按**内容 sha256** 判断有没有变化的，所以改正文比「再发一版」更直接；
     * 如果改的是当前下发版本，节点下一次轮询/`config sync` 就会取走新内容。
     */
    public function updateContent(NodeConfig $config, string $yaml, ?User $actor = null, string $reason = ''): NodeConfig
    {
        $this->assertValidYaml($yaml);

        return DB::transaction(function () use ($config, $yaml, $actor, $reason): NodeConfig {
            $before = $config->sha256;

            $config->forceFill([
                'content_yaml' => $yaml,
                'sha256' => hash('sha256', $yaml),
            ])->save();

            NodeConfigHistory::create([
                'node_id' => $config->node_id,
                'from_version' => $config->version,
                'to_version' => $config->version,
                'action' => NodeConfigHistory::ACTION_EDIT,
                'operator' => $actor?->name ?? 'system',
                'reason' => $reason,
                'meta' => ['sha256_before' => $before, 'sha256_after' => $config->sha256],
            ]);

            AuditLogger::log('config.edited', $config, [
                'node_id' => $config->node?->node_id,
                'version' => $config->version,
                'sha256_before' => $before,
                'sha256_after' => $config->sha256,
                'reason' => $reason,
            ], $actor?->name);

            return $config->refresh();
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
