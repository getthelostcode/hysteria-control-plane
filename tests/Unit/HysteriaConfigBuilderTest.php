<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HysteriaConfigBuilder;
use App\Support\YamlDiff;
use Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

class HysteriaConfigBuilderTest extends TestCase
{
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
}
