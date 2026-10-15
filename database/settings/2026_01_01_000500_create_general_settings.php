<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.brand_name', 'Hysteria Control Plane');
        $this->migrator->add('general.brand_logo', null);
        $this->migrator->add('general.theme_preset', 'ocean');
        $this->migrator->add('general.theme_primary', '');
        $this->migrator->add('general.theme_mode', 'system');
        $this->migrator->add('general.heartbeat_interval', 30);
        $this->migrator->add('general.offline_threshold', 90);
        $this->migrator->add('general.metrics_retention_days', 30);
        $this->migrator->add('general.node_domain', 'node.example.com');
        $this->migrator->add('general.default_config_template', '');
        $this->migrator->add('general.panel_footer', 'Hysteria2 Control Plane');
    }
};
