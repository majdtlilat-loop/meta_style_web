<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

use App\Kernel\Platform\Branding\Color;

/**
 * Turns a CenterBrand into the semantic `--center-*` tokens the public site's
 * stylesheet reads.
 *
 * Every value emitted is a validated `#rrggbb`, an `rgb()` built from one, a
 * gradient built from validated stops and an allow-listed angle, or a length
 * from a fixed table. The CSS text contains no quote, ampersand or angle
 * bracket, so it is safe to place inside a `<style>` element or a `style`
 * attribute through Blade's ordinary escaping.
 *
 * The chosen colours are never altered: pairs that read poorly are REPORTED
 * (contrastWarnings) so the owner decides. Supporting tokens (hover, soft
 * tints, the text drawn on a filled button) are derived from the choices.
 */
final class CenterTheme
{
    private const RADII = [
        'square' => ['sm' => '0', 'md' => '0', 'lg' => '0', 'button' => '0'],
        'soft' => ['sm' => '.25rem', 'md' => '.5rem', 'lg' => '.75rem', 'button' => '.375rem'],
        'rounded' => ['sm' => '.5rem', 'md' => '1rem', 'lg' => '1.5rem', 'button' => '.75rem'],
        'pill' => ['sm' => '.75rem', 'md' => '1.25rem', 'lg' => '2rem', 'button' => '999px'],
    ];

    /**
     * Colour tokens for one palette.
     *
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    public static function colorTokens(array $colors, bool $dark = false): array
    {
        $c = $colors + CenterBrand::DEFAULT_COLORS[$dark ? 'dark' : 'light'];
        $primaryStrong = $dark ? Color::mix($c['primary'], '#ffffff', 0.08) : Color::mix($c['primary'], '#000000', 0.12);

        return [
            '--center-primary' => $c['primary'],
            '--center-primary-strong' => $primaryStrong,
            '--center-on-primary' => Color::readableOn($c['primary'], '#ffffff', '#141012'),
            '--center-primary-soft' => Color::mix($c['primary'], $c['background'], $dark ? 0.84 : 0.9),
            '--center-primary-text' => self::readable($c['primary'], $c['background'], $dark),
            '--center-secondary' => $c['secondary'],
            '--center-on-secondary' => Color::readableOn($c['secondary'], '#ffffff', '#141012'),
            '--center-accent' => $c['accent'],
            '--center-on-accent' => Color::readableOn($c['accent'], '#ffffff', '#141012'),
            '--center-accent-soft' => Color::mix($c['accent'], $c['background'], $dark ? 0.84 : 0.85),
            '--center-bg' => $c['background'],
            '--center-surface' => $c['surface'],
            '--center-surface-strong' => Color::mix($c['surface'], $c['border'], 0.5),
            '--center-text' => $c['text'],
            '--center-muted' => $c['muted'],
            '--center-border' => $c['border'],
            '--center-header-bg' => Color::alpha($c['background'], 0.86),
            '--center-overlay' => Color::alpha($dark ? '#000000' : Color::mix($c['text'], '#000000', 0.4), 0.55),
        ];
    }

    /**
     * Gradient and shape tokens (the same in both schemes).
     *
     * @param  array<string, mixed>  $brand  a normalized CenterBrand
     * @return array<string, string>
     */
    public static function shapeTokens(array $brand): array
    {
        $radius = self::RADII[(string) ($brand['radius'] ?? 'rounded')] ?? self::RADII['rounded'];
        $tokens = [
            '--center-radius-sm' => $radius['sm'],
            '--center-radius' => $radius['md'],
            '--center-radius-lg' => $radius['lg'],
            '--center-radius-button' => $radius['button'],
        ];
        foreach (CenterBrand::GRADIENTS as $key) {
            $gradient = $brand['gradients'][$key] ?? CenterBrand::DEFAULT_GRADIENTS[$key];
            $tokens['--center-gradient-'.$key] = self::gradientCss($gradient);
        }

        return $tokens;
    }

    /**
     * Light tokens plus shape: what a single-scheme consumer (the public
     * menu, a print header) reads.
     *
     * @param  array<string, mixed>  $brand
     * @return array<string, string>
     */
    public static function tokens(array $brand): array
    {
        return self::colorTokens((array) ($brand['light'] ?? []), false) + self::shapeTokens($brand);
    }

    /**
     * @param  array{from?: string, via?: string, to?: string, angle?: int}  $gradient
     */
    public static function gradientCss(array $gradient): string
    {
        $stops = array_values(array_filter(
            [(string) ($gradient['from'] ?? ''), (string) ($gradient['via'] ?? ''), (string) ($gradient['to'] ?? '')],
            static fn (string $color): bool => Color::isHex($color),
        ));
        if (count($stops) < 2) {
            $stops = [CenterBrand::DEFAULT_GRADIENTS['brand']['from'], CenterBrand::DEFAULT_GRADIENTS['brand']['to']];
        }
        $angle = in_array($gradient['angle'] ?? null, CenterBrand::ANGLES, true) ? (int) $gradient['angle'] : 135;

        return 'linear-gradient('.$angle.'deg, '.implode(', ', $stops).')';
    }

    /**
     * The stylesheet a public page adds: light tokens on the root, the dark
     * palette on `.cs-dark` (a dark section) and — for the `auto` scheme —
     * when the visitor's device prefers dark, or always for `dark`.
     *
     * @param  array<string, mixed>  $brand
     */
    public static function css(array $brand): string
    {
        $light = self::declarations(self::colorTokens((array) ($brand['light'] ?? []), false) + self::shapeTokens($brand));
        $dark = self::declarations(self::colorTokens((array) ($brand['dark'] ?? []), true));
        $scheme = (string) ($brand['scheme'] ?? 'light');

        $css = ':root{'.$light.'}.cs-dark{'.$dark.'color:var(--center-text);}';
        if ($scheme === 'dark') {
            $css .= ':root{'.$dark.'}';
        } elseif ($scheme === 'auto') {
            $css .= '@media (prefers-color-scheme: dark){:root{'.$dark.'}}';
        }

        return $css;
    }

    /**
     * Inline custom properties for a preview container.
     *
     * @param  array<string, mixed>  $brand
     */
    public static function inlineStyle(array $brand, string $mode = 'light'): string
    {
        $dark = $mode === 'dark';

        return self::declarations(self::colorTokens((array) ($brand[$dark ? 'dark' : 'light'] ?? []), $dark) + self::shapeTokens($brand));
    }

    /**
     * Pairs below WCAG AA with the chosen colours. Reported, never fixed.
     *
     * @param  array<string, mixed>  $brand
     * @return list<array{mode: string, pair: string, ratio: float, minimum: float}>
     */
    public static function contrastWarnings(array $brand): array
    {
        $warnings = [];
        $modes = ($brand['scheme'] ?? 'light') === 'light' ? ['light'] : ['light', 'dark'];
        foreach ($modes as $mode) {
            $c = (array) ($brand[$mode] ?? []) + CenterBrand::DEFAULT_COLORS[$mode];
            $tokens = self::colorTokens($c, $mode === 'dark');
            $checks = [
                'text_background' => [$c['text'], $c['background'], 4.5],
                'text_surface' => [$c['text'], $c['surface'], 4.5],
                'muted_background' => [$c['muted'], $c['background'], 4.5],
                'button' => [$tokens['--center-on-primary'], $c['primary'], 4.5],
                'secondary_button' => [$tokens['--center-on-secondary'], $c['secondary'], 4.5],
                'primary_background' => [$c['primary'], $c['background'], 3.0],
            ];
            foreach ($checks as $pair => [$foreground, $background, $minimum]) {
                $ratio = Color::contrast($foreground, $background);
                if ($ratio < $minimum) {
                    $warnings[] = ['mode' => $mode, 'pair' => $pair, 'ratio' => round($ratio, 2), 'minimum' => $minimum];
                }
            }
        }

        return $warnings;
    }

    /**
     * The primary colour as link text: moved toward black (light) or white
     * (dark) only as far as it takes to read at 4.5:1 on the page.
     */
    private static function readable(string $primary, string $background, bool $dark): string
    {
        for ($weight = 0.0; $weight <= 0.7; $weight += 0.05) {
            $candidate = Color::mix($primary, $dark ? '#ffffff' : '#000000', $weight);
            if (Color::contrast($candidate, $background) >= 4.5) {
                return $candidate;
            }
        }

        return Color::mix($primary, $dark ? '#ffffff' : '#000000', 0.7);
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private static function declarations(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $name => $value) {
            $out .= $name.':'.$value.';';
        }

        return $out;
    }
}
