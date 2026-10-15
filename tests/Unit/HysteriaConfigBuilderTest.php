<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HysteriaConfigBuilder;
use App\Support\Setting;
use App\Support\YamlDiff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class HysteriaConfigBuilderTest extends TestCase
{
    // 需要真实库：spatie settings 在没有表的 :memory: 库上会静默失败，
    // 表现成「改了设置但模板不生效」的假故障（踩过）。
    use RefreshDatabase;

    public function test_default_template_renders_valid_yaml(): void
    {
        $rendered = HysteriaConfigBuilder::renderWithOverrides(
            new \App\Models\Node(['node_id' => 'nd_unit_1', 'name' => 'unit', 'secret_hash' => str_repeat('a', 64)]),
            ['bandwidth' => ['up' => '500 mbps']],
        );

        $parsed = Yaml::parse($rendered);

        $this->assertSame('500 mbps', $parsed['bandwidth']['up']);
        $this->assertSame('1 gbps', $parsed['bandwidth']['down']);
        $this->assertArrayHasKey('trafficStats', $parsed);
    }

    public function test_yaml_diff_reports_added_and_removed_lines(): void
    {
        $stats = YamlDiff::stats("a: 1\nb: 2\n", "a: 1\nc: 3\n");

        $this->assertSame(1, $stats['added']);
        $this->assertSame(1, $stats['removed']);
        // "a: 1" is untouched; the trailing empty line counts as unchanged too.
        $this->assertSame(2, $stats['same']);
        $this->assertStringContainsString('diff-added', $stats['html']);
        $this->assertStringContainsString('diff-removed', $stats['html']);
    }

    public function test_yaml_diff_escapes_html(): void
    {
        $stats = YamlDiff::stats('', '<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $stats['html']);
        $this->assertStringContainsString('&lt;script&gt;', $stats['html']);
    }

    /**
     * 节点配置必须走 HTTP 鉴权：用户是否可用由鉴权服务回答，而不是写在节点上的静态密码。
     */
    public function test_default_template_uses_http_auth_with_insecure_disabled(): void
    {
        $parsed = Yaml::parse(HysteriaConfigBuilder::render(
            new \App\Models\Node(['node_id' => 'nd_unit_http', 'name' => 'unit', 'secret_hash' => str_repeat('a', 64)]),
        ));

        $this->assertSame('http', $parsed['auth']['type']);
        $this->assertSame('http://auth.ipx.sarl', $parsed['auth']['http']['url']);
        $this->assertFalse($parsed['auth']['http']['insecure'], 'insecure 默认必须是 false（校验鉴权后端证书）');

        // trafficStats 仍要在：踢人与遥测都依赖它
        $this->assertArrayHasKey('trafficStats', $parsed);
    }

    public function test_auth_backend_is_configurable_from_settings(): void
    {
        Setting::setMany([
            'hysteria_auth_url' => 'https://auth.example.com/hysteria',
            'hysteria_auth_insecure' => true,
        ]);

        // spatie 把 Settings 类注册成单例：同一个请求里先读后写会拿到旧对象，必须丢弃实例。
        app()->forgetInstance(\App\Settings\AppSettings::class);

        $parsed = Yaml::parse(HysteriaConfigBuilder::render(
            new \App\Models\Node(['node_id' => 'nd_unit_http2', 'name' => 'unit', 'secret_hash' => str_repeat('a', 64)]),
        ));

        $this->assertSame('https://auth.example.com/hysteria', $parsed['auth']['http']['url']);
        $this->assertTrue($parsed['auth']['http']['insecure']);

        Setting::setMany(['hysteria_auth_url' => 'http://auth.ipx.sarl', 'hysteria_auth_insecure' => false]);
        app()->forgetInstance(\App\Settings\AppSettings::class);
    }
}
