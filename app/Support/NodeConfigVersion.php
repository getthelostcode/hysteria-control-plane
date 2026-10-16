<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Node;

/**
 * 节点列表「配置版本」一列的取值：**以节点实盘 /etc/hysteria/config.yaml 为准**。
 *
 * 数据来源是 agent 心跳自报的 nodes.meta：
 *   config_version（这份文件对应控制面的第几版）、config_sha256、config_path。
 * 面板自己的期望版本（版本管理的默认版本、node_configs.is_current）只用来对比，
 * 绝不拿来当显示值 —— 否则「节点落后 / 被手工改过」就看不出来了。
 */
class NodeConfigVersion
{
    /** 从未上报时的显示文案。 */
    public const UNREPORTED = '未上报';

    /** 上报了 sha256，但控制面里没有与之逐字节一致的版本（多半被手工改过）。 */
    public const UNKNOWN = '未识别';

    /**
     * @param  array<string, int>  $libraryBySha  版本管理里 sha256 → 版本号（用于给「认不出」的实盘内容对号）
     * @return array{0: string, 1: string, 2: string}  [显示文案, tooltip, 徽标颜色]
     */
    public static function resolve(Node $node, ?int $libraryDefault, array $libraryBySha = []): array
    {
        $reported = (int) ($node->meta['config_version'] ?? 0);
        $sha = (string) ($node->meta['config_sha256'] ?? '');
        $path = (string) ($node->meta['config_path'] ?? '/etc/hysteria/config.yaml');
        $shortSha = $sha !== '' ? substr($sha, 0, 12) : '';

        // 节点自报的版本号优先；没有就按实盘内容 sha 去版本管理里对号
        $onDisk = $reported > 0 ? $reported : (int) ($libraryBySha[$sha] ?? 0);

        if ($onDisk <= 0) {
            if ($sha === '') {
                return [
                    self::UNREPORTED,
                    '节点还没上报实盘配置（agent 心跳会带上 config_version / config_sha256；'
                        . '旧版 agent 不上报，升级一次即可）',
                    'gray',
                ];
            }

            return [
                self::UNKNOWN,
                '节点实盘 ' . $path . ' 的 sha256=' . $shortSha . '…，控制面里没有对应版本（可能被手工改过）'
                    . ($libraryDefault !== null ? '；版本管理默认版本 v' . $libraryDefault : ''),
                'danger',
            ];
        }

        $intro = '节点实盘 ' . $path . '：v' . $onDisk
            . '（sha256 ' . ($shortSha !== '' ? $shortSha . '…' : '未上报') . '）';

        if ($libraryDefault === null) {
            return ['v' . $onDisk, $intro . '；版本管理里还没有默认版本', 'gray'];
        }

        return $onDisk === $libraryDefault
            ? ['v' . $onDisk, $intro . '；与版本管理默认版本一致', 'success']
            : ['v' . $onDisk, $intro . '；版本管理默认版本 v' . $libraryDefault . '（待同步）', 'warning'];
    }
}
