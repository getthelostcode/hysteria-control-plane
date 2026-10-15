<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AppSettings extends Settings
{
    public string $brand_name = 'Hysteria Control Plane';

    public ?string $brand_logo = null;

    public string $theme_preset = 'ocean';

    public string $theme_primary = '';

    public string $theme_mode = 'system';

    public int $heartbeat_interval = 30;

    public int $offline_threshold = 90;

    public int $metrics_retention_days = 30;

    public string $node_domain = 'example.com';

    public string $default_config_template = '';

    /**
     * Hysteria 的 HTTP 鉴权后端（auth.type=http）。
     *
     * 节点上用 `auth.http.url` 指向它，用户是否可用由该服务决定（Hysteria 会把鉴权请求
     * 原样转发过去）。`insecure: true` 表示跳过该 URL 的证书校验 —— 默认 false，
     * 只有自签证书的私网后端才应该打开。
     *
     * 用 https：鉴权请求会带用户凭据，明文 http 等于把凭据交给链路上的任何人。
     * 证书由 Cloudflare/反代在边缘终止，源站仍可以是 http://127.0.0.1:<port>。
     */
    public string $hysteria_auth_url = 'https://auth.ipx.sarl';

    public bool $hysteria_auth_insecure = false;

    public string $panel_footer = 'Hysteria2 Control Plane';

    public static function group(): string
    {
        return 'general';
    }
}
