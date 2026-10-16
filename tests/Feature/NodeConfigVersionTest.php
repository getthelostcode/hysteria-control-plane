<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Node;
use App\Support\NodeConfigVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 节点列表「配置版本」的取值：**以节点实盘 config.yaml（agent 心跳自报）为准**。
 */
class NodeConfigVersionTest extends TestCase
{
    use RefreshDatabase;

    private function nodeWithMeta(array $meta): Node
    {
        return Node::factory()->make(['meta' => $meta]);
    }

    public function test_uses_the_version_the_node_actually_has_on_disk(): void
    {
        $node = $this->nodeWithMeta(['config_version' => '1', 'config_sha256' => str_repeat('a', 64)]);

        // 节点实盘 v1，而版本管理的默认版本是 v2 → 显示实盘的 v1（黄色「待同步」）
        $this->assertSame(['v1', 'warning'], [
            NodeConfigVersion::resolve($node, 2)[0],
            NodeConfigVersion::resolve($node, 2)[2],
        ]);

        // 与默认版本一致 → 绿色（默认版本 v1，节点实盘也是 v1）
        $this->assertSame(['v1', 'success'], [
            NodeConfigVersion::resolve($node, 1)[0],
            NodeConfigVersion::resolve($node, 1)[2],
        ]);
    }

    public function test_matches_by_sha_when_the_agent_could_not_map_a_version(): void
    {
        $sha = str_repeat('b', 64);
        $node = $this->nodeWithMeta(['config_sha256' => $sha]);   // 没自报版本号

        [$label, , $color] = NodeConfigVersion::resolve($node, 5, [$sha => 5]);   // 第 2 项是 tooltip

        $this->assertSame('v5', $label);
        $this->assertSame('success', $color);
    }

    public function test_hand_edited_config_is_flagged_not_papered_over(): void
    {
        $node = $this->nodeWithMeta(['config_sha256' => str_repeat('c', 64)]);

        [$label, $tip, $color] = NodeConfigVersion::resolve($node, 2);

        $this->assertSame(NodeConfigVersion::UNKNOWN, $label);
        $this->assertSame('danger', $color);
        $this->assertStringContainsString('没有对应版本', $tip);
        $this->assertStringContainsString('sha256', $tip);
    }

    public function test_never_reports_a_panel_expectation_when_the_node_says_nothing(): void
    {
        $node = $this->nodeWithMeta([]);

        [$label, $tip, $color] = NodeConfigVersion::resolve($node, 7);

        $this->assertSame(NodeConfigVersion::UNREPORTED, $label);
        $this->assertSame('gray', $color);
        $this->assertStringNotContainsString('v7', $label);           // 不拿面板的期望版本充数
        $this->assertStringContainsString('还没上报', $tip);
    }
}
