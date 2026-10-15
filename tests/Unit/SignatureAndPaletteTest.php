<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ColorPalette;
use App\Support\SignatureVerifier;
use App\Support\YamlDiff;
use Tests\TestCase;

class SignatureAndPaletteTest extends TestCase
{
    public function test_signature_is_a_stable_hmac_of_the_canonical_string(): void
    {
        $signature = SignatureVerifier::sign('secret', 'post', '/api/agent/v1/heartbeat', '{"a":1}', 1700000000, 'nonce-1');

        $canonical = implode("\n", [
            'POST',
            '/api/agent/v1/heartbeat',
            hash('sha256', '{"a":1}'),
            '1700000000',
            'nonce-1',
        ]);

        $this->assertSame(hash_hmac('sha256', $canonical, 'secret'), $signature);
        $this->assertSame(64, strlen($signature));
    }

    public function test_signature_changes_with_every_component(): void
    {
        $base = SignatureVerifier::sign('s', 'POST', '/p', 'b', 1, 'n');

        $this->assertNotSame($base, SignatureVerifier::sign('s2', 'POST', '/p', 'b', 1, 'n'));
        $this->assertNotSame($base, SignatureVerifier::sign('s', 'GET', '/p', 'b', 1, 'n'));
        $this->assertNotSame($base, SignatureVerifier::sign('s', 'POST', '/q', 'b', 1, 'n'));
        $this->assertNotSame($base, SignatureVerifier::sign('s', 'POST', '/p', 'b2', 1, 'n'));
        $this->assertNotSame($base, SignatureVerifier::sign('s', 'POST', '/p', 'b', 2, 'n'));
        $this->assertNotSame($base, SignatureVerifier::sign('s', 'POST', '/p', 'b', 1, 'n2'));
    }

    public function test_palette_derives_a_full_scale_and_rgb_channels(): void
    {
        $palette = ColorPalette::fromHex('#3b82f6');

        $this->assertCount(11, $palette);
        $this->assertSame('#3b82f6', $palette['500']);
        $this->assertSame('59, 130, 246', ColorPalette::rgbChannels($palette['500']));
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['50']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['950']);
    }

    public function test_invalid_colour_falls_back_to_the_brand_blue(): void
    {
        $this->assertSame('59, 130, 246', ColorPalette::rgbChannels('not-a-colour'));
    }

    public function test_diff_handles_empty_inputs(): void
    {
        $this->assertSame([], YamlDiff::compare('', ''));
        $this->assertCount(1, YamlDiff::compare('', 'a'));
        $this->assertSame('same', YamlDiff::compare('a', 'a')[0]['type']);
    }
}
