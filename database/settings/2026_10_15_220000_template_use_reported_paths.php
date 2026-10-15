<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 已存模板里的写死值换回占位符。
 *
 * 面板不知道节点上的证书路径与 trafficStats 监听地址：写死一个值，节点上对不上时
 * agent 会**整份拒收**配置（jp8 连发四版全 failed），而面板上完全看不出为什么。
 * 换成 {{cert_path}} / {{key_path}} / {{stats_listen}} 后由节点自报的真实值渲染。
 */
return new class extends SettingsMigration
{
    /** 写死值 → 占位符。只替换「等于常见默认值」的那些，运维特殊写法不动。 */
    private const REPLACEMENTS = [
        'cert: /etc/hysteria/certs/server.crt' => 'cert: {{cert_path}}',
        'key: /etc/hysteria/certs/server.key' => 'key: {{key_path}}',
        'listen: 127.0.0.1:8080' => 'listen: {{stats_listen}}',
        'listen: 127.0.0.1:9999' => 'listen: {{stats_listen}}',
    ];

    public function up(): void
    {
        try {
            $template = \App\Support\Setting::get('default_config_template', '');

            if (! is_string($template) || trim($template) === '') {
                return;
            }

            $patched = str_replace(array_keys(self::REPLACEMENTS), array_values(self::REPLACEMENTS), $template);

            // trafficStats 段里的 listen 才是要换的那个；上面按整行替换已经足够精确。
            if ($patched !== $template) {
                \App\Support\Setting::set('default_config_template', $patched);
            }
        } catch (\Throwable) {
            // 设置表不可用（安装早期）时跳过，不阻断安装。
        }
    }
};
