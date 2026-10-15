<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ConfigVersions;
use App\Models\Node;
use App\Models\NodeConfig;
use App\Services\ConfigService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/** 「配置管理 → 版本管理」页：所有版本一览 + 改正文 + 指定默认下发版本。 */
class ConfigVersionsPageTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_page_lists_versions_of_every_node_and_marks_the_default_one(): void
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
            ->assertCanSeeTableRecords([$a1, $a2, $b1])
            ->assertSee('node-a')
            ->assertSee('node-b');

        // 每台节点当前默认下发的那一版都被标出来
        $this->assertTrue($a2->refresh()->is_current);
        $this->assertTrue($b1->refresh()->is_current);
    }

    public function test_default_version_can_be_switched_from_the_page(): void
    {
        $this->actingAs($this->adminUser());
        $configs = app(ConfigService::class);

        $node = Node::factory()->create(['name' => 'node-c']);
        $v1 = $configs->publish($node, "listen: :443\n");
        $v2 = $configs->publish($node, "listen: :8443\n");

        $this->assertTrue($v2->refresh()->is_current);

        // 用 refresh 过的记录：publish() 返回的模型里 is_current 还是创建时的值
        Livewire::test(ConfigVersions::class)
            ->callTableAction('activate', $v1->refresh(), ['reason' => '就下 v1']);

        $this->assertTrue($v1->refresh()->is_current);
        $this->assertFalse($v2->refresh()->is_current);
        $this->assertSame($v1->version, $configs->latest($node)?->version);
    }

    public function test_content_can_be_edited_from_the_page(): void
    {
        $this->actingAs($this->adminUser());
        $configs = app(ConfigService::class);

        $node = Node::factory()->create(['name' => 'node-d']);
        $v1 = $configs->publish($node, "listen: :443\n");
        $before = $v1->sha256;

        Livewire::test(ConfigVersions::class)
            ->callTableAction('edit', $v1, [
                'content_yaml' => "listen: :443\n# edited on versions page\n",
                'reason' => '页面改正文',
            ]);

        $v1->refresh();
        $this->assertNotSame($before, $v1->sha256);
        $this->assertStringContainsString('edited on versions page', (string) $v1->content_yaml);
        $this->assertSame($v1->version, $configs->latest($node)?->version, '改的就是默认下发版本');
    }

    public function test_publishing_without_making_it_default_keeps_the_old_default(): void
    {
        $this->actingAs($this->adminUser());
        $configs = app(ConfigService::class);

        $node = Node::factory()->create(['name' => 'node-e']);
        $v1 = $configs->publish($node, "listen: :443\n");
        $v2 = $configs->publish($node, "listen: :8443\n", null, '草稿', [], false);

        $this->assertTrue($v1->refresh()->is_current);
        $this->assertFalse($v2->refresh()->is_current, '不勾「设为默认下发」时不该顶掉当前版本');
        $this->assertSame($v1->version, $configs->latest($node)?->version);
    }
}
