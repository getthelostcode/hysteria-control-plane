<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Node;

/**
 * 节点列表「配置版本」一列的取值：**以节点上报的 config_sha256 为准去反查版本号**。
 *
 * 为什么按 sha256 而不是按节点自报的版本号：sha256 是内容的指纹，版本号只是记账。
 * 节点自报的版本号可能因为回滚、手工改过、面板就地改过正文而与本机内容对不上
 * （真实现场：某节点上报 version=2，而它的 sha256 正是版本管理里 v1 的那份内容）。
 *
 * 解析顺序（先到先用）：
 *   1. 版本管理（config_files）里 sha256 命中的版本；
 *   2. 该节点自己的配置历史（node_configs）里 sha256 命中的版本；
 *   3. 都没命中才退回节点自报的版本号，并在悬停里写明「sha256 未命中」；
 *   4. 连 sha256 都没有 → 「未上报」。
 *
 * 面板自己的期望版本（版本管理默认版本 / node_configs.is_current）只用于对比与配色，
 * 绝不当作显示值 —— 否则「落后」「被手工改过」两类节点就看不出来了。
 */
class NodeConfigVersion
{
    /** 从未上报时的显示文案。 */
    public const UNREPORTED = '未上报';

    /** 上报了 sha256，但控制面里没有与之逐字节一致的版本。 */
    public const UNKNOWN = '未识别';

    /**
     * @param  array<string, int>  $libraryBySha  版本管理里的 sha256 → 版本号
     * @param  (callable(Node, string): ?int)|null  $nodeVersionBySha  该节点历史里的 sha256 → 版本号（懒查）
     * @return array{0: string, 1: string, 2: string}  [显示文案, tooltip, 徽标颜色]
     */
    public static function resolve(Node $node, ?int $libraryDefault, array $libraryBySha = [], ?callable $nodeVersionBySha = null): array
    {
        $reported = (int) ($node->meta['config_version'] ?? 0);
        $sha = strtolower(trim((string) ($node->meta['config_sha256'] ?? '')));
        $path = (string) ($node->meta['config_path'] ?? '/etc/hysteria/config.yaml');
        $shortSha = $sha !== '' ? substr($sha, 0, 12) : '';

        if ($sha === '' && $reported <= 0) {
            return [
                self::UNREPORTED,
                '节点还没上报实盘配置（agent 心跳会带上 config_sha256 / config_version；旧版 agent 不上报，升级一次即可）',
                'gray',
            ];
        }

        // 1) 版本管理里按 sha256 反查
        $onDisk = $sha !== '' ? (int) ($libraryBySha[$sha] ?? 0) : 0;
        $basis = $onDisk > 0 ? '版本管理' : '';

        // 2) 本节点配置历史里按 sha256 反查
        if ($onDisk <= 0 && $sha !== '' && $nodeVersionBySha !== null) {
            $found = $nodeVersionBySha($node, $sha);

            if ($found !== null && (int) $found > 0) {
                $onDisk = (int) $found;
                $basis = '本节点配置历史';
            }
        }

        // 3) sha256 都没命中 → 退回节点自报的版本号（并如实标注）
        if ($onDisk <= 0 && $reported > 0) {
            $onDisk = $reported;
            $basis = '节点自报（sha256 未命中控制面版本）';
        }

        if ($onDisk <= 0) {
            return [
                self::UNKNOWN,
                '节点实盘 ' . $path . ' 的 sha256=' . $shortSha . '…，控制面里没有对应版本（可能被手工改过）'
                    . ($libraryDefault !== null ? '；版本管理默认版本 v' . $libraryDefault : ''),
                'danger',
            ];
        }

        $intro = '节点实盘 ' . $path . '：v' . $onDisk
            . '（sha256 ' . ($shortSha !== '' ? $shortSha . '…' : '未上报') . '，按' . $basis . '认定）';

        if ($libraryDefault === null) {
            return ['v' . $onDisk, $intro . '；版本管理里还没有默认版本', 'gray'];
        }

        return $onDisk === $libraryDefault
            ? ['v' . $onDisk, $intro . '；与版本管理默认版本一致', 'success']
            : ['v' . $onDisk, $intro . '；版本管理默认版本 v' . $libraryDefault . '（待同步）', 'warning'];
    }
}
