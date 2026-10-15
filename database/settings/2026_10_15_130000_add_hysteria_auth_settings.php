<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 补上 Hysteria HTTP 鉴权后端的设置键。
 *
 * 注意：spatie 的 settings 迁移在已有部署上不会重跑 2026_01_01_000500，所以新增键
 * 必须单独一条迁移，否则老库上读取会拿到 null（表现为模板里 {{auth_url}} 渲染成空）。
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.hysteria_auth_url')) {
            $this->migrator->add('general.hysteria_auth_url', 'http://auth.ipx.sarl');
        }

        if (! $this->migrator->exists('general.hysteria_auth_insecure')) {
            $this->migrator->add('general.hysteria_auth_insecure', false);
        }
    }
};
