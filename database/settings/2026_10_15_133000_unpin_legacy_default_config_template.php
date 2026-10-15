<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 把被「钉住」的旧内置模板清空，让它重新跟随 HysteriaConfigBuilder::DEFAULT_TEMPLATE。
 *
 * 背景：SettingsSeeder 曾经把内置模板原样写进设置表，等于把模板冻结在安装那一刻。
 * 现在内置模板的 auth 从 userpass 换成了 HTTP 鉴权，但已有部署读到的还是库里那份旧副本
 * （面板里看着像是"已经配好了"）。只有当存量值与旧的**内置**模板一字不差时才清空 ——
 * 运维自己改过的模板不动（那是有意为之）。
 */
return new class extends SettingsMigration
{
    private const LEGACY_DEFAULT_TEMPLATE = <<<'YAML'
listen: :{{listen_port}}

tls:
  cert: {{cert_path}}
  key: {{key_path}}

obfs:
  type: salamander
  salamander:
    password: {{obfs_password}}

auth:
  type: userpass
  userpass:

trafficStats:
  listen: 127.0.0.1:9999
  secret: {{stats_secret}}

masquerade:
  type: file
  file:
    dir: {{masquerade}}

bandwidth:
  up: 1 gbps
  down: 1 gbps

quic:
  initStreamReceiveWindow: 8388608
  maxStreamReceiveWindow: 8388608
  initConnReceiveWindow: 20971520
  maxConnReceiveWindow: 20971520
  maxIdleTimeout: 30s
  keepAlivePeriod: 10s
YAML;

    public function up(): void
    {
        try {
            $stored = \App\Support\Setting::get('default_config_template', '');

            if (is_string($stored) && trim($stored) === trim(self::LEGACY_DEFAULT_TEMPLATE)) {
                \App\Support\Setting::set('default_config_template', '');
            }
        } catch (\Throwable) {
            // 设置表还没建/数据库不可用时静默跳过：这条迁移只做数据整理，不该拦住安装。
        }
    }
};
