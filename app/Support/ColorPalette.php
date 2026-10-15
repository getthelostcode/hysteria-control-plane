<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builds Tailwind-style colour scales.
 *
 * Filament v3 consumes shades as raw RGB channels ("59, 130, 246") because its
 * Tailwind preset wraps them in rgba(var(--primary-500), <alpha-value>).
 */
final class ColorPalette
{
    /** @return array<string, string> hex shades keyed 50..950 */
    public static function fromHex(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = '3b82f6';
        }

        [$h, $s, $l] = self::toHsl($hex);

        $targets = [
            '50' => 97, '100' => 93, '200' => 86, '300' => 76, '400' => 65,
            '500' => 55, '600' => 46, '700' => 38, '800' => 30, '900' => 24, '950' => 15,
        ];

        $palette = [];

        foreach ($targets as $shade => $lightness) {
            // keep the hue, curve the saturation slightly towards the extremes
            $saturation = $shade === '500' ? $s : max(18.0, $s - abs(55 - $lightness) * 0.55);
            $palette[$shade] = self::fromHsl($h, $saturation, (float) $lightness);
        }

        $palette['500'] = '#' . strtolower($hex);

        return $palette;
    }

    /** "59, 130, 246" — the format Filament's CSS variables use. */
    public static function rgbChannels(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return '59, 130, 246';
        }

        return sprintf(
            '%d, %d, %d',
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    /** @return array{0: float, 1: float, 2: float} hue, saturation %, lightness % */
    private static function toHsl(string $hex): array
    {
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $h = 0.0;
        $s = 0.0;

        if ($d > 0.0) {
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            $h = match (true) {
                $max === $r => (($g - $b) / $d) + ($g < $b ? 6 : 0),
                $max === $g => (($b - $r) / $d) + 2,
                default => (($r - $g) / $d) + 4,
            };

            $h *= 60;
        }

        return [$h, $s * 100, $l * 100];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $s /= 100;
        $l /= 100;

        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return sprintf('#%02x%02x%02x',
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        );
    }
}
