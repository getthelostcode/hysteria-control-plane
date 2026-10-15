<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSettings;
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
}
