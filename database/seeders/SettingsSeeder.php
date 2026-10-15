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
        // 留空 = 用 HysteriaConfigBuilder::DEFAULT_TEMPLATE。
        // 曾经这里把内置模板**钉**进设置表：模板后来改了（auth 从 userpass 换成 http），
        // 已有部署却因为库里那份旧副本而继续用旧模板，且面板上看着像是"已配置"，
        // 排查成本极高。需要自定义模板时由运维在面板里显式填写。
        $settings->default_config_template = '';
        $settings->save();

        $this->command?->info('Panel settings written.');
    }
}
