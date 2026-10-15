<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ConfigFile;
use App\Services\ConfigFileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 配置版本文件库的服务层：版本自增、默认唯一、编辑重算 sha256。 */
class ConfigFileServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_versions_increment_and_only_one_default(): void
    {
        $files = app(ConfigFileService::class);

        $v1 = $files->create("listen: :443\n");
        $v2 = $files->create("listen: :8443\n");

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertSame(1, ConfigFile::query()->default()->count(), '默认版本必须唯一');
        $this->assertSame($v2->version, $files->default()?->version);

        // 指定旧版本为默认 → 默认唯一性仍然成立
        $files->setDefault($v1->refresh());

        $this->assertSame(1, ConfigFile::query()->default()->count());
        $this->assertSame($v1->version, $files->default()?->version);
    }

    public function test_editing_recomputes_sha256(): void
    {
        $files = app(ConfigFileService::class);
        $file = $files->create("listen: :443\n");
        $before = $file->sha256;

        $updated = $files->updateContent($file, "listen: :443\n# edited\n");

        $this->assertNotSame($before, $updated->sha256);
        $this->assertSame(hash('sha256', (string) $updated->content_yaml), $updated->sha256);
        $this->assertDatabaseHas('audit_logs', ['action' => 'config_file.edited']);
    }

    public function test_default_falls_back_to_newest_when_nothing_is_marked(): void
    {
        $files = app(ConfigFileService::class);
        $files->create("listen: :443\n");
        $v2 = $files->create("listen: :8443\n");

        DB::table('config_files')->update(['is_default' => false]);

        $this->assertSame($v2->version, $files->default()?->version, '没有标记时退回最新一版');
    }

    public function test_invalid_yaml_is_rejected(): void
    {
        $this->expectException(\App\Exceptions\ConfigValidationException::class);

        app(ConfigFileService::class)->create("listen: [::1\n");
    }
}
