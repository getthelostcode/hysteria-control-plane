<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ConfigVersions;
use App\Filament\Resources\NodeConfigResource\Pages\ViewNodeConfig;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * 「配置管理 → 版本管理」：一次一台节点，版本只出现一次；列与行操作按需求精简。
 */
class ConfigVersionsPageTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** 一次只显示选中节点的版本（每个版本只出现一次），且列按需求精简。 */
    public function test_list_shows_selected_nodes_versions_once_each(): void
    {
        $this->actingAs($this->adminUser());
        $configs = app(ConfigService::class);

        $a = Node::factory()->create(['name' => 'node-a']);
        $b = Node::factory()->create(['name' => 'node-b']);

        $a1 = $configs->publish($a, "listen: :443\n", null, 'a-v1');
        $a2 = $configs->publish($a, "listen: :8443\n", null, 'a-v2');
        $b1 = $configs->publish($b, "listen: :9443\n", null, 'b-v1');

        Livewire::test(ConfigVersions::class)
            ->assertSuccessful()
            // 默认选中第一台节点：只有它的版本，别的节点的不出现
            ->assertCanSeeTableRecords([$a1, $a2])
            ->assertCanNotSeeTableRecords([$b1])
            ->assertTableColumnExists('version')
            ->assertTableColumnExists('status')
            ->assertTableColumnExists('published_at')
            ->assertTableColumnExists('updated_at')
            ->assertTableColumnExists('applied_at')
            // 不显示：节点列、sha256、默认版本列
            ->assertTableColumnDoesNotExist('node.name')
            ->assertTableColumnDoesNotExist('sha256')
            ->assertTableColumnDoesNotExist('is_current');

        // 切换节点 → 看到那台的版本
        Livewire::test(ConfigVersions::class)
            ->set('data.node_id', $b->id)
            ->assertCanSeeTableRecords([$b1])
            ->assertCanNotSeeTableRecords([$a1, $a2]);
    }

    /** 行操作只保留「设为默认下发」；查看/查看正文/编辑正文/回滚/对比都去掉。 */
    public function test_row_actions_are_reduced_to_set_default_only(): void
    {
        $this->actingAs($this->adminUser());
        $configs = app(ConfigService::class);

        $node = Node::factory()->create(['name' => 'node-actions']);
        $v1 = $configs->publish($node, "listen: :443\n");
        $v2 = $configs->publish($node, "listen: :8443\n");

        $test = Livewire::test(ConfigVersions::class)
            ->assertTableActionExists('activate', null, $v1);

        foreach (['view', 'content', 'edit', 'rollback', 'diff'] as $removed) {
            $test->assertTableActionDoesNotExist($removed);
        }

        // 指定默认下发的版本仍然管用（用 refresh 过的记录：publish 返回的模型 is_current 是旧值）
        $test->callTableAction('activate', $v1->refresh(), ['reason' => '就下 v1']);

        $this->assertTrue($v1->refresh()->is_current);
        $this->assertFalse($v2->refresh()->is_current);
        $this->assertSame($v1->version, $configs->latest($node)?->version);
    }

    /** 版本仍可修改：点「版本」进详情页改正文（列表里不再放编辑按钮）。 */
    public function test_versions_are_still_editable_from_the_detail_page(): void
    {
        $this->actingAs($this->adminUser());
        $configs = app(ConfigService::class);

        $node = Node::factory()->create(['name' => 'node-edit']);
        $v1 = $configs->publish($node, "listen: :443\n");
        $before = $v1->sha256;

        Livewire::test(ViewNodeConfig::class, ['record' => $v1->getKey()])
            ->callAction('edit_content', [
                'content_yaml' => "listen: :443\n# edited from detail\n",
                'reason' => '详情页改正文',
            ]);

        $v1->refresh();
        $this->assertNotSame($before, $v1->sha256);
        $this->assertStringContainsString('edited from detail', (string) $v1->content_yaml);
    }

    public function test_create_version_button_publishes_and_can_skip_becoming_default(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create(['name' => 'node-new']);
        $v1 = app(ConfigService::class)->publish($node, "listen: :443\n");

        Livewire::test(ConfigVersions::class)
            ->callAction('create_version', [
                'node_id' => $node->id,
                'content_yaml' => "listen: :8443\n",
                'make_current' => true,
                'reason' => '页面创建',
            ]);

        $v2 = NodeConfig::query()->where('node_id', $node->id)->where('version', 2)->firstOrFail();
        $this->assertTrue($v2->is_current);
        $this->assertFalse($v1->refresh()->is_current);

        Livewire::test(ConfigVersions::class)
            ->callAction('create_version', [
                'node_id' => $node->id,
                'content_yaml' => "listen: :9443\n",
                'make_current' => false,
                'reason' => '草稿',
            ]);

        $v3 = NodeConfig::query()->where('node_id', $node->id)->where('version', 3)->firstOrFail();
        $this->assertFalse($v3->is_current, '不勾「设为默认下发」时不该顶掉当前版本');
        $this->assertTrue($v2->refresh()->is_current);
    }

    /**
     * 走真实 HTTP（真实布局）：Livewire::test 不走页面布局，布局出错它抓不到 ——
     * 部署到生产就变成 500「Livewire page component layout view not found」。
     */
    public function test_page_renders_over_http_with_the_panel_layout(): void
    {
        $this->actingAs($this->adminUser());

        $node = Node::factory()->create(['name' => 'http-node']);
        // 两版：旧的那版才会显示「设为默认下发」行操作
        app(ConfigService::class)->publish($node, "listen: :443\n");
        app(ConfigService::class)->publish($node, "listen: :8443\n");

        $response = $this->get('/admin/config-versions');

        $response->assertOk();
        $contents = $response->getContent();

        $this->assertStringContainsString('配置版本管理', $contents);
        $this->assertStringContainsString('创建版本', $contents);
        $this->assertStringContainsString('发布时间', $contents);
        $this->assertStringContainsString('最后修改时间', $contents);
        $this->assertStringContainsString('设为默认下发', $contents);

        // 精简掉的列与行操作不该出现在列表页
        $this->assertStringNotContainsString('>节点<', $contents);
        $this->assertStringNotContainsString('sha256 ', $contents);

        foreach (['查看正文', '编辑正文', '回滚到此版本', '对比'] as $removed) {
            $this->assertStringNotContainsString($removed, $contents);
        }
    }
}
