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
        $config = NodeConfig::query()->where('node_id', $node->id)->where('version', $version)->first();

        if ($config === null) {
            // 只在「回执的版本号正好等于当前默认版本文件」时才接受 —— 那就是 agent 按文件库同步的结果，
            // 补记一行让该节点的应用历史完整。其余未知版本号仍然 404（保持原有契约，别让脏回执进库）。
            $default = app(ConfigFileService::class)->default();

            if ($default === null || (int) $default->version !== $version) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(NodeConfig::class, [$version]);
            }

            $config = NodeConfig::create([
                'node_id' => $node->id,
                'version' => $version,
                'content_yaml' => $default->content_yaml,
                'sha256' => (string) $default->sha256,
                'status' => NodeConfig::STATUS_PUBLISHED,
                'is_current' => false,
                'published_at' => now(),
            ]);

            AuditLogger::log('config.applied_from_library', $config, [
                'node_id' => $node->node_id,
                'version' => $version,
                'status' => $status,
            ], 'agent');
        }

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

            // 回滚风暴护栏 ①：最新一版已经是「回滚目标的内容」了 → 再回滚只是换个版本号，
            // 节点环境的问题（端口被占、证书不对）不会因此消失。真实现场：jp8 的 agent 反复回执
            // v2 failed，面板每轮回滚都新建一版，5 秒一版涨到 v460。
            $newest = NodeConfig::query()->where('node_id', $node->id)->orderByDesc('version')->first();

            if ($fallback !== null && $newest !== null
                && (string) $newest->sha256 === (string) $fallback->sha256
                && (int) $newest->version > $version) {
                AuditLogger::log('config.auto_rollback_skipped', $config, [
                    'node_id' => $node->node_id,
                    'version' => $version,
                    'newest_version' => $newest->version,
                    'reason' => '最新版本已是回滚目标内容（sha256 一致），跳过以免回滚风暴',
                ], 'system');

                $node->refresh();
                $node->status = Node::STATUS_ERROR;
                $node->save();

                return $config->refresh();
            }

            // 回滚风暴护栏 ②：同一节点短时间内已经自动回滚过多次 → 说明是环境问题而非内容问题，
            // 不再自动生成新版本，把失败原因留在面板上让人去修。
            $recentRollbacks = NodeConfigHistory::query()
                ->where('node_id', $node->id)
                ->where('action', NodeConfigHistory::ACTION_ROLLBACK)
                ->where('created_at', '>=', now()->subMinutes(10))
                ->where('reason', 'like', 'auto rollback%')
                ->count();

            if ($fallback !== null && $recentRollbacks >= 3) {
                AuditLogger::log('config.auto_rollback_skipped', $config, [
                    'node_id' => $node->node_id,
                    'version' => $version,
                    'recent_auto_rollbacks' => $recentRollbacks,
                    'reason' => '10 分钟内自动回滚已达 ' . $recentRollbacks . ' 次，停止自动回滚（疑似环境问题）',
                ], 'system');

                $node->refresh();
                $node->status = Node::STATUS_ERROR;
                $node->save();

                return $config->refresh();
            }

            // 回滚风暴护栏 ③：要回滚到的内容与刚失败的版本**是同一份**时，回滚毫无意义 ——
            // 节点会以完全相同的内容再失败一次、再触发一次回滚，于是每轮一个新版本，
            // 控制面版本号一路涨（真实现场：jp8 每 5 秒新增一版，几分钟涨到 v446）。
            // 这种情况通常是「环境问题」而非「内容问题」（端口被 systemd 的 hysteria 占着、
            // 证书路径不对…），回滚救不了，只能如实把错误留在面板上让人去修。
            if ($fallback !== null && (string) $fallback->sha256 === (string) $config->sha256) {
                AuditLogger::log('config.auto_rollback_skipped', $config, [
                    'node_id' => $node->node_id,
                    'version' => $version,
                    'fallback_version' => $fallback->version,
                    'reason' => '回滚目标与失败版本内容相同（sha256 一致），跳过以免形成回滚循环',
                ], 'system');

                $node->refresh();
                $node->status = Node::STATUS_ERROR;
                $node->save();

                return $config->refresh();
            }

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
