<?php

declare(strict_types=1);

namespace App\Support;

use App\Settings\AppSettings;
use Throwable;

/**
 * Thin accessor over spatie/laravel-settings that never explodes when the
 * database is unavailable (installer / early boot / migrations).
 */
final class Setting
{
    /** @var array<string, array<string, string>> hex shades per preset */
    public const PRESETS = [
        'ocean' => [
            '50' => '#eff6ff', '100' => '#dbeafe', '200' => '#bfdbfe', '300' => '#93c5fd',
            '400' => '#60a5fa', '500' => '#3b82f6', '600' => '#2563eb', '700' => '#1d4ed8',
            '800' => '#1e40af', '900' => '#1e3a8a', '950' => '#172554',
        ],
        'forest' => [
            '50' => '#ecfdf5', '100' => '#d1fae5', '200' => '#a7f3d0', '300' => '#6ee7b7',
            '400' => '#34d399', '500' => '#10b981', '600' => '#059669', '700' => '#047857',
            '800' => '#065f46', '900' => '#064e3b', '950' => '#022c22',
        ],
        'sunset' => [
            '50' => '#fff7ed', '100' => '#ffedd5', '200' => '#fed7aa', '300' => '#fdba74',
            '400' => '#fb923c', '500' => '#f97316', '600' => '#ea580c', '700' => '#c2410c',
            '800' => '#9a3412', '900' => '#7c2d12', '950' => '#431407',
        ],
    ];

    public const PRESET_LABELS = [
        'ocean' => 'Ocean (蓝)',
        'forest' => 'Forest (绿)',
        'sunset' => 'Sunset (橙)',
    ];

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $settings = app(AppSettings::class);
            $value = $settings->{$key} ?? null;

            return ($value === null || $value === '') ? $default : $value;
        } catch (Throwable) {
            return $default;
        }
    }

    public static function set(string $key, mixed $value): bool
    {
        return self::setMany([$key => $value]);
    }

    /** @param array<string, mixed> $values */
    public static function setMany(array $values): bool
    {
        try {
            $settings = app(AppSettings::class);

            foreach ($values as $key => $value) {
                $settings->{$key} = $value;
            }

            $settings->save();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    public static function preset(?string $name = null): array
    {
        $name ??= (string) self::get('theme_preset', 'ocean');

        return self::PRESETS[$name] ?? self::PRESETS['ocean'];
    }

    /** The effective palette as hex shades: custom primary wins over the preset. */
    public static function palette(): array
    {
        $custom = (string) self::get('theme_primary', '');

        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $custom) === 1) {
            return ColorPalette::fromHex($custom);
        }

        return self::preset();
    }

    /** Resolve the effective primary color as hex. */
    public static function primaryColor(): string
    {
        return self::palette()['500'];
    }

    /** CSS custom property block consumed by the admin theme (HEAD_END hook). */
    public static function cssVariables(): string
    {
        $lines = [];

        foreach (self::palette() as $shade => $hex) {
            $lines[] = sprintf('--primary-%s:%s;', $shade, ColorPalette::rgbChannels($hex));
            $lines[] = sprintf('--cp-primary-%s:%s;', $shade, $hex);
        }

        $lines[] = sprintf('--cp-theme-preset:"%s";', (string) self::get('theme_preset', 'ocean'));

        return ':root{' . implode('', $lines) . '}';
    }

    /** @return array<string, string> */
    public static function presetsAsCssVars(): array
    {
        $out = [];

        foreach (self::PRESETS as $name => $shades) {
            $parts = [];

            foreach ($shades as $shade => $hex) {
                $parts[] = sprintf('--primary-%s:%s;', $shade, ColorPalette::rgbChannels($hex));
            }

            $out[$name] = implode('', $parts);
        }

        return $out;
    }
}
