<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConfigFile;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * 配置版本文件库：创建 / 编辑 / 指定默认版本。
 *
 * 与 ConfigService（给某台节点下发配置）分开：这里只管**文件**，不涉及任何节点，
 * 所以一台节点都没有也能先攒版本，之后下发给节点时挑一份即可。
 */
final class ConfigFileService
{
    /** 新建一个版本文件（版本号自增；默认成为全库默认版本）。 */
    public function create(string $yaml, ?User $actor = null, string $note = '', bool $makeDefault = true): ConfigFile
    {
        app(ConfigService::class)->assertValidYaml($yaml);

        return DB::transaction(function () use ($yaml, $actor, $note, $makeDefault): ConfigFile {
            $version = (int) ConfigFile::query()->max('version') + 1;

            $file = ConfigFile::create([
                'version' => $version,
                'note' => $note,
                'content_yaml' => $yaml,
                'sha256' => hash('sha256', $yaml),
                'is_default' => $makeDefault,
                'created_by' => $actor?->id,
            ]);

            if ($makeDefault) {
                $this->clearOtherDefaults($file);
            }

            AuditLogger::log('config_file.created', $file, [
                'version' => $version,
                'sha256' => $file->sha256,
                'is_default' => $makeDefault,
                'note' => $note,
            ], $actor?->name);

            return $file;
        });
    }

    /** 就地改正文（sha256 重算）。 */
    public function updateContent(ConfigFile $file, string $yaml, ?User $actor = null, string $reason = ''): ConfigFile
    {
        app(ConfigService::class)->assertValidYaml($yaml);

        return DB::transaction(function () use ($file, $yaml, $actor, $reason): ConfigFile {
            $before = $file->sha256;

            $file->forceFill([
                'content_yaml' => $yaml,
                'sha256' => hash('sha256', $yaml),
            ])->save();

            AuditLogger::log('config_file.edited', $file, [
                'version' => $file->version,
                'sha256_before' => $before,
                'sha256_after' => $file->sha256,
                'reason' => $reason,
            ], $actor?->name);

            return $file->refresh();
        });
    }

    /** 指定全库默认版本。 */
    public function setDefault(ConfigFile $file, ?User $actor = null, string $reason = ''): ConfigFile
    {
        return DB::transaction(function () use ($file, $actor, $reason): ConfigFile {
            $file->forceFill(['is_default' => true])->save();
            $this->clearOtherDefaults($file);

            AuditLogger::log('config_file.default_set', $file, [
                'version' => $file->version,
                'reason' => $reason,
            ], $actor?->name);

            return $file->refresh();
        });
    }

    /** 默认版本文件（没有标记时退回最新一版）。 */
    public function default(): ?ConfigFile
    {
        return ConfigFile::query()->default()->orderByDesc('version')->first()
            ?? ConfigFile::query()->orderByDesc('version')->first();
    }

    private function clearOtherDefaults(ConfigFile $keep): void
    {
        ConfigFile::query()
            ->whereKeyNot($keep->getKey())
            ->update(['is_default' => false]);
    }
}
