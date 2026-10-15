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

    public string $panel_footer = 'Hysteria2 Control Plane';

    public static function group(): string
    {
        return 'general';
    }
}
