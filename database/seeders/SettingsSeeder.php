<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Settings\AppSettings;
use App\Support\HysteriaConfigBuilder;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = app(AppSettings::class);

        $settings->brand_name = 'Hysteria Control Plane';
        $settings->theme_preset = 'ocean';
        $settings->theme_primary = '';
        $settings->theme_mode = 'system';
        $settings->heartbeat_interval = 30;
        $settings->offline_threshold = 90;
        $settings->metrics_retention_days = 30;
        $settings->node_domain = 'node.example.com';
        $settings->panel_footer = 'Hysteria2 Control Plane';
        $settings->default_config_template = HysteriaConfigBuilder::DEFAULT_TEMPLATE;
        $settings->save();

        $this->command?->info('Panel settings written.');
    }
}
