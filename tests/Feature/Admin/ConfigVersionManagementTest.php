<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Node;
use App\Models\NodeConfig;
use App\Models\NodeConfigHistory;
use App\Services\ConfigService;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * 「查看/修改所有配置版本 + 指定默认下发哪一版」。
 *
 * 背景：以前「默认下发」= version 最大的那条，运维没法说「就下第 3 版」，也没法就地改某一版。
 */
class ConfigVersionManagementTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    private function publish(Node $node, string $yaml, string $reason = ''): NodeConfig
    {
        return app(ConfigService::class)->publish($node, $yaml, null, $reason);
    }

    /**
     * 用真实的注册流程拿一台有凭据的节点（兼容面 ?key= 只认注册产生的凭据）。
     *
     * @return array{0: Node, 1: string, 2: string}  节点、node_id、node_secret
     */
    private function enrolledNode(): array
    {
        [, $plain] = \App\Models\EnrollToken::issue(['max_uses' => 1]);

        $response = $this->postJson('/api/v1/agent/enroll', [
            'enroll_token' => $plain,
            'hostname' => 'cfg-version-1',
            'os' => 'linux',
            'arch' => 'amd64',
            'machine_id' => 'cfg-version-' . bin2hex(random_bytes(4)),
            'agent_version' => '0.1.0',
            'hysteria_version' => '2.6.0',
            'protocol' => '1',
        ])->assertOk();

        $nodeId = (string) $response->json('node_id');
        $secret = (string) $response->json('node_secret');

        return [Node::query()->where('node_id', $nodeId)->firstOrFail(), $nodeId, $secret];
    }

    public function test_newest_published_version_becomes_the_default_one(): void
    {
        $node = Node::factory()->create();
        $v1 = $this->publish($node, "listen: :443\n");
        $v2 = $this->publish($node, "listen: :8443\n");

        $this->assertFalse($v1->refresh()->is_current, '旧版本不该还是默认下发');
        $this->assertTrue($v2->refresh()->is_current);
        $this->assertSame($v2->version, app(ConfigService::class)->latest($node)?->version);
    }

    public function test_any_version_can_be_designated_as_the_default(): void
    {
        $node = Node::factory()->create();
        $v1 = $this->publish($node, "listen: :443\n");
        $v2 = $this->publish($node, "listen: :8443\n");

        $current = app(ConfigService::class)->setCurrent($node, $v1->version, null, '就下第一版');

        $this->assertTrue($current->is_current);
        $this->assertFalse($v2->refresh()->is_current);
        // latest() 是面板与 agent 共用的「默认下发」来源，必须跟着走
        $this->assertSame($v1->version, app(ConfigService::class)->latest($node)?->version);
        $this->assertDatabaseHas('node_config_history', [
            'node_id' => $node->id,
            'action' => NodeConfigHistory::ACTION_ACTIVATE,
            'to_version' => $v1->version,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'config.activated']);
    }

    public function test_agent_gets_the_designated_version_not_the_highest_one(): void
    {
        [$node, $nodeId, $secret] = $this->enrolledNode();
        $v1 = $this->publish($node, "listen: :443\n");
        $this->publish($node, "listen: :8443\n");

        app(ConfigService::class)->setCurrent($node, $v1->version);

        $this->getJson("/api/v1/agent/config/latest?current_version=0&key={$secret}&node_id={$nodeId}")
            ->assertOk()
            ->assertJsonPath('up_to_date', false)
            ->assertJsonPath('config.version', $v1->version)
            ->assertJsonPath('config.content_yaml', "listen: :443\n");
    }

    public function test_editing_a_version_recomputes_sha_and_nodes_see_it(): void
    {
        $node = Node::factory()->create();
        $v1 = $this->publish($node, "listen: :443\n");
        $oldSha = $v1->sha256;

        // 就地改正文：sha256 必须变（节点就是按它判断有没有变化的）
        $updated = app(ConfigService::class)->updateContent($v1, "listen: :443\nauth:\n  type: password\n", null, '补一段 auth');

        $this->assertNotSame($oldSha, $updated->sha256);
        $this->assertSame(hash('sha256', (string) $updated->content_yaml), $updated->sha256);
        $this->assertSame($v1->version, $updated->version, '就地修改不新生成版本');
        $this->assertDatabaseHas('node_config_history', [
            'node_id' => $node->id,
            'action' => NodeConfigHistory::ACTION_EDIT,
            'to_version' => $v1->version,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'config.edited']);
    }

    public function test_agent_keeping_the_same_version_but_old_sha_still_gets_the_edited_content(): void
    {
        [$node, $nodeId, $secret] = $this->enrolledNode();
        $v1 = $this->publish($node, "listen: :443\n");
        $staleSha = $v1->sha256;

        $updated = app(ConfigService::class)->updateContent($v1, "listen: :443\n# edited\n");

        // 版本号没变、但内容变了：带旧 sha 请求必须拿到新正文（否则「改了却下发不下去」）
        $this->getJson("/api/v1/agent/config/latest?current_version={$updated->version}&current_sha256={$staleSha}&key={$secret}&node_id={$nodeId}")
            ->assertOk()
            ->assertJsonPath('up_to_date', false)
            ->assertJsonPath('config.sha256', $updated->sha256);

        // 内容已经一致：明确 up_to_date
        $this->getJson("/api/v1/agent/config/latest?current_version={$updated->version}&current_sha256={$updated->sha256}&key={$secret}&node_id={$nodeId}")
            ->assertOk()
            ->assertJsonPath('up_to_date', true);
    }

    public function test_admin_api_can_show_edit_and_activate_versions(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();
        $v1 = $this->publish($node, "listen: :443\n");
        $v2 = $this->publish($node, "listen: :8443\n");

        // 查看正文
        $this->getJson("/api/admin/v1/nodes/{$node->node_id}/configs/{$v1->version}")
            ->assertOk()
            ->assertJsonPath('content_yaml', "listen: :443\n")
            ->assertJsonPath('data.is_current', false);

        // 列表里能看出哪个是默认下发
        $this->getJson("/api/admin/v1/nodes/{$node->node_id}/configs")
            ->assertOk()
            ->assertJsonPath('active_version', $v2->version);

        // 编辑某一版正文
        $this->patchJson("/api/admin/v1/nodes/{$node->node_id}/configs/{$v1->version}", [
            'content_yaml' => "listen: :443\n# edited via api\n", 'reason' => 'api edit',
        ])->assertOk()->assertJsonPath('data.sha256', hash('sha256', "listen: :443\n# edited via api"));

        // 指定默认下发的版本
        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs/{$v1->version}/activate", ['reason' => '就下这版'])
            ->assertOk()
            ->assertJsonPath('active_version', $v1->version);

        $this->assertTrue($v1->refresh()->is_current);
        $this->assertFalse($v2->refresh()->is_current);
    }

    public function test_viewer_cannot_edit_or_activate(): void
    {
        $node = Node::factory()->create();
        $v1 = $this->publish($node, "listen: :443\n");

        $this->actingAs($this->viewerUser());

        $this->patchJson("/api/admin/v1/nodes/{$node->node_id}/configs/{$v1->version}", [
            'content_yaml' => "listen: :443\n",
        ])->assertForbidden();

        $this->postJson("/api/admin/v1/nodes/{$node->node_id}/configs/{$v1->version}/activate")
            ->assertForbidden();
    }
}
