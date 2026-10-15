<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 鉴权后端默认值从 http 改成 https。
 *
 * 鉴权请求带用户凭据，明文 http 等于把凭据交给链路上的任何人，不该是默认值。
 * 只切换「还等于旧默认值」的部署 —— 运维自己填过地址的不动。
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        try {
            $current = \App\Support\Setting::get('hysteria_auth_url', '');

            if (is_string($current) && trim($current) === 'http://auth.ipx.sarl') {
                \App\Support\Setting::set('hysteria_auth_url', 'https://auth.ipx.sarl');
            }
        } catch (\Throwable) {
            // 设置表不可用（安装早期）时跳过：这条只做默认值迁移，不该拦住安装。
        }
    }
};
