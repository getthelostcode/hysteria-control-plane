<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\ConfigFiles;
use App\Models\ConfigFile;
use App\Services\ConfigFileService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesPanelUsers;
use Tests\TestCase;

/**
 * 「配置管理 → 版本管理」= 配置版本文件库：管文件，不管节点。
 */
class ConfigFilesPageTest extends TestCase
{
    use CreatesPanelUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_page_lists_config_version_files(): void
    {
        $this->actingAs($this->adminUser());
        $files = app(ConfigFileService::class);

        $v1 = $files->create("listen: :443\n", null, '第一版');
        $v2 = $files->create("listen: :8443\n", null, '第二版');

        Livewire::test(ConfigFiles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$v1, $v2])
            ->assertTableColumnExists('version')
            ->assertTableColumnExists('note')
            ->assertTableColumnExists('sha256')
            ->assertTableColumnStateSet('sha256', $v1->sha256, $v1)
            ->assertTableColumnStateSet('sha256', $v2->sha256, $v2)
            ->assertTableColumnExists('created_at')
            ->assertTableColumnExists('updated_at')
            // 与节点无关：没有节点列
            ->assertTableColumnDoesNotExist('node.name')
            ->assertTableColumnDoesNotExist('node_id');
    }

    public function test_create_edit_and_set_default_from_the_page(): void
    {
        $this->actingAs($this->adminUser());

        // 创建
        Livewire::test(ConfigFiles::class)
            ->callAction('create_version', [
                'content_yaml' => "listen: :443\n# v1\n",
                'note' => '首页版本',
                'make_default' => true,
            ]);

        $v1 = ConfigFile::query()->where('version', 1)->firstOrFail();
        $this->assertTrue($v1->is_default);
        $this->assertSame(hash('sha256', (string) $v1->content_yaml), $v1->sha256);

        // 再建一版但不设为默认 → 默认仍是 v1
        Livewire::test(ConfigFiles::class)
            ->callAction('create_version', [
                'content_yaml' => "listen: :8443\n# v2\n",
                'note' => '草稿',
                'make_default' => false,
            ]);

        $v2 = ConfigFile::query()->where('version', 2)->firstOrFail();
        $this->assertFalse($v2->is_default);
        $this->assertTrue($v1->refresh()->is_default);

        // 编辑正文
        $before = $v2->sha256;
        Livewire::test(ConfigFiles::class)
            ->callTableAction('edit', $v2, [
                'content_yaml' => "listen: :8443\n# v2 edited\n",
                'reason' => '改一版',
            ]);

        $v2->refresh();
        $this->assertNotSame($before, $v2->sha256);
        $this->assertStringContainsString('v2 edited', (string) $v2->content_yaml);

        // 顶部「当前默认版本」下拉 + 保存
        Livewire::test(ConfigFiles::class)
            ->fillForm(['default_version_id' => $v2->refresh()->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($v2->refresh()->is_default);
        $this->assertFalse($v1->refresh()->is_default);
        $this->assertSame($v2->version, app(ConfigFileService::class)->default()?->version);
    }

    public function test_page_renders_over_http(): void
    {
        $this->actingAs($this->adminUser());
        app(ConfigFileService::class)->create("listen: :443\n", null, 'HTTP 渲染');

        $response = $this->get('/admin/config-files');

        $response->assertOk();
        $contents = $response->getContent();
        $this->assertStringContainsString('配置版本文件', $contents);
        $this->assertStringContainsString('创建版本', $contents);
        $this->assertStringContainsString('当前默认版本', $contents);
        $this->assertStringContainsString('config_sha256', $contents);
        $this->assertStringContainsString('保存', $contents);
        $this->assertStringContainsString('发布时间', $contents);
        $this->assertStringContainsString('最后修改时间', $contents);
        $this->assertStringNotContainsString('>节点<', $contents);
    }

    /** 顶部「当前默认版本」下拉默认选中当前默认版本，保存后指针跟着走。 */
    public function test_default_version_selector_is_prefilled_and_saved(): void
    {
        $this->actingAs($this->adminUser());
        $files = app(ConfigFileService::class);

        $v1 = $files->create("listen: :443\n", null, '第一版');
        $v2 = $files->create("listen: :8443\n", null, '第二版');   // 默认 = v2

        Livewire::test(ConfigFiles::class)
            // 下拉预选当前默认版本（v2）
            ->assertFormSet(['default_version_id' => $v2->getKey()])
            ->assertSee('当前默认版本')
            ->assertSee('保存')
            // 改选 v1 并保存
            ->fillForm(['default_version_id' => $v1->getKey()])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('v1 已设为默认版本');

        $this->assertTrue($v1->refresh()->is_default);
        $this->assertFalse($v2->refresh()->is_default);
        $this->assertSame($v1->version, $files->default()?->version);
    }
}
