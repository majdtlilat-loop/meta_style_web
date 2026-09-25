<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Branding;

/**
 * Small sRGB helpers for the platform theme: parse a `#rrggbb` value, mix two
 * colours, and measure WCAG 2.2 contrast. Only ever fed validated hex values.
 */
final class Color
{
    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/', $value) === 1;
    }

    /** Lower-cased `#rrggbb`, or null for anything else (3-digit, names, functions …). */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = mb_strtolower(trim($value));

        return self::isHex($value) ? $value : null;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /** `weight` of `$other` mixed into `$base` (0 = base, 1 = other). */
    public static function mix(string $base, string $other, float $weight): string
    {
        [$r1, $g1, $b1] = self::rgb($base);
        [$r2, $g2, $b2] = self::rgb($other);
        $channel = static fn (int $a, int $b): string => str_pad(dechex((int) round($a + ($b - $a) * $weight)), 2, '0', STR_PAD_LEFT);

        return '#'.$channel($r1, $r2).$channel($g1, $g2).$channel($b1, $b2);
    }

    /** `rgb(r g b / alpha)` for translucent tokens. */
    public static function alpha(string $hex, float $alpha): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return sprintf('rgb(%d %d %d / %s)', $r, $g, $b, rtrim(rtrim(number_format($alpha, 2, '.', ''), '0'), '.'));
    }

    public static function luminance(string $hex): float
    {
        $linear = static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = self::rgb($hex);

        return 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
    }

    /** WCAG contrast ratio, 1–21. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Whichever of the two candidates reads better on `$background`. */
    public static function readableOn(string $background, string $light = '#ffffff', string $dark = '#1c1114'): string
    {
        return self::contrast($light, $background) >= self::contrast($dark, $background) ? $light : $dark;
    }
}
