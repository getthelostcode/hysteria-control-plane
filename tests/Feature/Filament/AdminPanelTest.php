<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\NodeResource\Pages\ListNodes;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Node;
use App\Support\Setting;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_login_page_is_reachable_and_branded(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Hysteria Control Plane');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_renders_the_key_widgets_with_initial_data(): void
    {
        $this->actingAs($this->adminUser());
        Node::factory()->count(3)->create();

        $this->get('/admin')
            ->assertOk()
            // StatsOverviewWidget is intentionally not lazy: the numbers are in the first paint.
            ->assertSee('节点总数')
            ->assertSee('在线率')
            ->assertSee('活跃用户');
    }

    public function test_every_resource_page_loads(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create();
        app(\App\Services\ConfigService::class)->publish($node, "listen: :443\n");

        foreach ([
            '/admin/nodes',
            '/admin/node-configs',
            '/admin/node-commands',
            '/admin/node-commands/create',
            '/admin/enroll-tokens',
            '/admin/enroll-tokens/create',
            '/admin/upgrade-packages',
            '/admin/audit-logs',
            '/admin/node-logs',
            '/admin/users',
            "/admin/nodes/{$node->node_id}",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_node_view_page_shows_metrics_and_config_history(): void
    {
        $this->actingAs($this->adminUser());
        $node = Node::factory()->create(['name' => 'hk-edge-9']);

        app(\App\Services\MetricsService::class)->ingest($node, [['cpu' => 42, 'mem' => 51]]);
        app(\App\Services\ConfigService::class)->publish($node, "listen: :443\n");

        $this->get("/admin/nodes/{$node->node_id}")
            ->assertOk()
            ->assertSee('hk-edge-9')
            ->assertSee('实时指标');
    }

    public function test_settings_page_persists_brand_and_theme_preset(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(ManageSettings::class)
            ->fillForm([
                'brand_name' => 'My Hysteria Fleet',
                'theme_preset' => 'sunset',
                'theme_primary' => '',
                'theme_mode' => 'dark',
                'heartbeat_interval' => 20,
                'offline_threshold' => 60,
                'metrics_retention_days' => 10,
                'node_domain' => 'nodes.internal',
                'panel_footer' => 'internal panel',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('My Hysteria Fleet', Setting::get('brand_name'));
        $this->assertSame('sunset', Setting::get('theme_preset'));
        $this->assertSame('#f97316', Setting::primaryColor());

        $css = Setting::cssVariables();
        $this->assertStringContainsString('--primary-500:249, 115, 22;', $css);
    }

    public function test_theme_css_variables_follow_a_custom_primary_colour(): void
    {
        Setting::setMany(['theme_preset' => 'forest', 'theme_primary' => '#ff0088']);

        $this->assertSame('#ff0088', Setting::primaryColor());
        $this->assertStringContainsString('--primary-500:255, 0, 136;', Setting::cssVariables());
    }

    public function test_admin_theme_bundle_is_registered_on_the_panel(): void
    {
        $panel = Filament::getPanel('admin');

        $this->assertSame('admin', $panel->getId());
        $this->assertSame('admin', $panel->getPath());
        $this->assertTrue($panel->hasDarkMode());
        $this->assertNotNull($panel->getBrandName());
        $this->assertSame('super-admin', $this->superAdmin()->getRoleNames()->first());
    }

    public function test_viewer_role_sees_read_only_panel_access(): void
    {
        $this->actingAs($this->viewerUser());

        $this->get('/admin/nodes')->assertOk();
        $this->get('/admin/nodes/create')->assertStatus(403);
        $this->get('/admin/upgrade-packages/create')->assertStatus(403);
    }

    public function test_stats_widget_numbers_track_the_fleet(): void
    {
        $this->actingAs($this->adminUser());
        Node::factory()->count(2)->create();
        Node::factory()->offline()->create();

        Livewire::test(StatsOverviewWidget::class)
            ->assertSee('节点总数')
            ->assertSee('3');
    }

    /** 配置版本资源列表也要能真渲染（列/布局变更最容易在这一步翻车）。 */
    public function test_node_config_resource_pages_render_over_http(): void
    {
        $this->actingAs($this->adminUser());

        $node = Node::factory()->create(['name' => 'list-node']);
        $config = app(\App\Services\ConfigService::class)->publish($node, "listen: :443\n");

        $this->get('/admin/node-configs')
            ->assertOk()
            ->assertSee('默认版本')
            ->assertSee('修改时间');

        $this->get('/admin/node-configs/' . $config->getKey())
            ->assertOk()
            ->assertSee('编辑正文');
    }

    /** 节点列表的「配置版本」以**节点实盘 config.yaml**为准（agent 心跳自报的版本号/sha256）。 */
    public function test_node_list_shows_the_version_reported_by_the_node_disk(): void
    {
        $this->actingAs($this->adminUser());

        $files = app(\App\Services\ConfigFileService::class);
        $files->create("listen: :443\n", null, '库 v1');
        $lib = $files->create("listen: :8443\n", null, '库 v2');   // 版本管理默认版本 = v2

        // 节点 A：实盘就是库 v2（已同步）
        $synced = Node::factory()->create([
            'name' => 'disk-synced',
            'meta' => ['config_version' => (string) $lib->version, 'config_sha256' => (string) $lib->sha256, 'config_path' => '/etc/hysteria/config.yaml'],
        ]);

        // 节点 B：实盘还是 panel v1（落后一版，只是 sha 对不上任何库文件）
        $behind = Node::factory()->create([
            'name' => 'disk-behind',
            'meta' => ['config_version' => '3', 'config_sha256' => str_repeat('a', 64)],
        ]);

        // 节点 C：实盘被手工改过 —— 认不出对应版本，但 sha256 有上报
        $handEdited = Node::factory()->create([
            'name' => 'disk-hand-edited',
            'meta' => ['config_sha256' => str_repeat('b', 64)],
        ]);

        // 节点 D：还没上报
        $silent = Node::factory()->create(['name' => 'disk-silent', 'meta' => []]);

        Livewire::test(ListNodes::class)
            ->assertSuccessful()
            ->assertTableColumnStateSet('disk_config_version', 'v' . $lib->version, $synced)
            ->assertTableColumnStateSet('disk_config_version', 'v3', $behind->refresh())
            ->assertTableColumnStateSet('disk_config_version', '未识别', $handEdited->refresh())
            ->assertTableColumnStateSet('disk_config_version', \App\Support\NodeConfigVersion::UNREPORTED, $silent->refresh());
    }

    /** 节点列表必须同时显示「最后心跳」与「配置版本」两列。 */
    public function test_node_list_shows_last_heartbeat_and_config_version(): void
    {
        $this->actingAs($this->adminUser());

        $fresh = Node::factory()->create([
            'name' => 'hb-fresh',
            'last_seen_at' => now()->subSeconds(20),
            'meta' => ['config_version' => '4', 'config_sha256' => str_repeat('d', 64)],
        ]);
        $stale = Node::factory()->create([
            'name' => 'hb-stale',
            'last_seen_at' => now()->subMinutes(30),
        ]);

        $html = $this->get('/admin/nodes')->assertOk()->getContent();
        $this->assertStringContainsString('最后心跳', $html);
        $this->assertStringContainsString('配置版本', $html);

        Livewire::test(ListNodes::class)
            ->assertSuccessful()
            ->assertTableColumnExists('last_seen_at')
            ->assertTableColumnExists('disk_config_version')
            // 相对时间渲染：20 秒前 → 「20 seconds ago」/「秒前」；半小时前 → 「30 minutes ago」/「分钟前」
            ->assertTableColumnStateSet('last_seen_at', $fresh->last_seen_at, $fresh)
            ->assertTableColumnStateSet('disk_config_version', 'v4', $fresh);
    }
}
