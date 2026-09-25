<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Branding;

use DomainException;

/**
 * The platform's colour identity as STRUCTURED values — twelve named colours
 * per theme and four named gradients — turned into the semantic CSS tokens the
 * stylesheets already read (`--color-canvas`, `--color-primary`, …,
 * `--gradient-primary`).
 *
 * Nothing here accepts CSS: a colour is `#rrggbb`, a gradient is two or three
 * such colours and an angle. So a stored theme can never carry a declaration,
 * a url() or a closing tag into the page.
 *
 * Rose Gold Luxe is the default, and the default emits NOTHING: tokens.css
 * already holds its hand-tuned values, so an untouched theme renders exactly
 * as before. Only a colour that differs from its default produces overrides,
 * with its supporting tokens (hover, soft, border …) derived from it. The
 * chosen colour itself is never altered; low contrast is reported, not fixed.
 */
final class PlatformTheme
{
    public const MODES = ['light', 'dark'];

    public const COLORS = ['background', 'surface', 'primary', 'secondary', 'accent', 'text', 'muted', 'border', 'success', 'warning', 'danger', 'info'];

    public const GRADIENTS = ['primary', 'hero', 'accent', 'cta'];

    public const ANGLES = [0, 45, 90, 135, 180, 225, 270, 315];

    public const DEFAULT_COLORS = [
        'light' => [
            'background' => '#ffffff', 'surface' => '#fff8f5', 'primary' => '#b76e79', 'secondary' => '#6b4226',
            'accent' => '#e4c3ad', 'text' => '#2e2119', 'muted' => '#6e5a4e', 'border' => '#eedfd5',
            'success' => '#1e7a50', 'warning' => '#955709', 'danger' => '#b4334a', 'info' => '#1f6784',
        ],
        'dark' => [
            'background' => '#121113', 'surface' => '#1a181b', 'primary' => '#d6939d', 'secondary' => '#d9b393',
            'accent' => '#e4c3ad', 'text' => '#f2ecea', 'muted' => '#b0a5a4', 'border' => '#2f2b31',
            'success' => '#6ccb98', 'warning' => '#e6b566', 'danger' => '#f08a99', 'info' => '#7bc0dd',
        ],
    ];

    /**
     * What a gradient starts from when an administrator switches it on. While
     * it is off, the stylesheet's own default gradient applies.
     */
    public const DEFAULT_GRADIENTS = [
        'primary' => ['enabled' => false, 'from' => '#6b4226', 'via' => '#a8616d', 'to' => '#b76e79', 'angle' => 135],
        'hero' => ['enabled' => false, 'from' => '#4d2f1b', 'via' => '#8f4f5b', 'to' => '#b76e79', 'angle' => 135],
        'accent' => ['enabled' => false, 'from' => '#fbedef', 'via' => '', 'to' => '#fff0e5', 'angle' => 135],
        'cta' => ['enabled' => false, 'from' => '#a8616d', 'via' => '', 'to' => '#b76e79', 'angle' => 90],
    ];

    /**
     * tokens.css, verbatim, grouped by the colour each token follows — used
     * whenever that colour is still the default, so the default is exact.
     */
    private const DEFAULT_TOKENS = [
        'light' => [
            'background' => ['--color-canvas' => '#ffffff', '--color-topbar' => 'rgb(255 255 255 / .9)', '--color-surface-elevated' => '#ffffff', '--color-input' => '#ffffff'],
            'surface' => ['--color-surface' => '#fff8f5', '--color-sidebar' => '#fff8f5', '--color-surface-muted' => '#fbf0ea', '--color-hover' => '#faede6', '--color-skeleton' => '#f3e6de', '--color-input-disabled' => '#f6eee9', '--color-disabled' => '#f1e6df'],
            'primary' => ['--color-primary' => '#b76e79', '--color-primary-strong' => '#a8616d', '--color-primary-strong-hover' => '#955561', '--color-on-primary' => '#ffffff', '--color-primary-text' => '#9a5662', '--color-primary-soft' => '#fbedef', '--color-primary-border' => '#ebc9cf', '--color-focus' => '#b76e79', '--color-selected' => 'rgb(183 110 121 / .11)'],
            'secondary' => ['--color-secondary' => '#6b4226', '--color-chocolate' => '#6b4226'],
            'accent' => ['--color-accent' => '#e4c3ad', '--color-accent-soft' => '#fff0e5'],
            'text' => ['--color-text' => '#2e2119', '--color-heading' => '#2e2119'],
            'muted' => ['--color-text-muted' => '#6e5a4e', '--color-text-subtle' => '#857063', '--color-icon' => '#8a7568', '--color-neutral' => '#6e5a4e', '--color-disabled-text' => '#8f7b6f', '--color-neutral-soft' => '#f5ede8', '--color-neutral-border' => '#e6d7cd'],
            'border' => ['--color-border' => '#eedfd5', '--color-border-strong' => '#dfc8ba', '--color-divider' => '#f3e7df'],
            'success' => ['--color-success' => '#1e7a50', '--color-success-soft' => '#e8f4ec', '--color-success-border' => '#c0e0cc'],
            'warning' => ['--color-warning' => '#955709', '--color-warning-soft' => '#fdf2e0', '--color-warning-border' => '#f0d3a6'],
            'danger' => ['--color-danger' => '#b4334a', '--color-danger-soft' => '#fcebee', '--color-danger-border' => '#f1c3cc'],
            'info' => ['--color-info' => '#1f6784', '--color-info-soft' => '#e6f1f6', '--color-info-border' => '#bcd9e6'],
        ],
        'dark' => [
            'background' => ['--color-canvas' => '#121113', '--color-topbar' => 'rgb(18 17 19 / .84)', '--color-input' => '#151316'],
            'surface' => ['--color-surface' => '#1a181b', '--color-sidebar' => '#161417', '--color-surface-muted' => '#211e22', '--color-surface-elevated' => '#242126', '--color-hover' => '#27242a', '--color-skeleton' => '#262328', '--color-input-disabled' => '#1e1b1f', '--color-disabled' => '#26232a'],
            'primary' => ['--color-primary' => '#d6939d', '--color-primary-strong' => '#cc8691', '--color-primary-strong-hover' => '#d99ba5', '--color-on-primary' => '#1c1114', '--color-primary-text' => '#e3a7b0', '--color-primary-soft' => 'rgb(214 147 157 / .13)', '--color-primary-border' => 'rgb(214 147 157 / .38)', '--color-focus' => '#e3a3ad', '--color-selected' => 'rgb(214 147 157 / .14)'],
            'secondary' => ['--color-secondary' => '#d9b393', '--color-chocolate' => '#d9b393'],
            'accent' => ['--color-accent' => '#e4c3ad', '--color-accent-soft' => 'rgb(228 195 173 / .09)'],
            'text' => ['--color-text' => '#f2ecea', '--color-heading' => '#f7f2f0'],
            'muted' => ['--color-text-muted' => '#b0a5a4', '--color-text-subtle' => '#8f8585', '--color-icon' => '#a89c9c', '--color-neutral' => '#b0a5a4', '--color-disabled-text' => '#7d7474', '--color-neutral-soft' => 'rgb(176 165 164 / .1)', '--color-neutral-border' => 'rgb(176 165 164 / .26)'],
            'border' => ['--color-border' => '#2f2b31', '--color-border-strong' => '#423c45', '--color-divider' => '#262229'],
            'success' => ['--color-success' => '#6ccb98', '--color-success-soft' => 'rgb(108 203 152 / .12)', '--color-success-border' => 'rgb(108 203 152 / .32)'],
            'warning' => ['--color-warning' => '#e6b566', '--color-warning-soft' => 'rgb(230 181 102 / .12)', '--color-warning-border' => 'rgb(230 181 102 / .32)'],
            'danger' => ['--color-danger' => '#f08a99', '--color-danger-soft' => 'rgb(240 138 153 / .12)', '--color-danger-border' => 'rgb(240 138 153 / .34)'],
            'info' => ['--color-info' => '#7bc0dd', '--color-info-soft' => 'rgb(123 192 221 / .12)', '--color-info-border' => 'rgb(123 192 221 / .32)'],
        ],
    ];

    /** @return array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>} */
    public static function defaults(): array
    {
        return ['light' => self::DEFAULT_COLORS['light'], 'dark' => self::DEFAULT_COLORS['dark'], 'gradients' => self::DEFAULT_GRADIENTS];
    }

    /**
     * Submitted values, validated to the allow-listed shape. Throws on the
     * first value that is not a `#rrggbb` colour or an offered angle.
     *
     * @param  array<string, mixed>  $input
     * @return array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>}
     */
    public static function normalize(array $input): array
    {
        $clean = self::defaults();
        foreach (self::MODES as $mode) {
            $colors = is_array($input[$mode] ?? null) ? $input[$mode] : [];
            foreach (self::COLORS as $key) {
                $value = Color::normalize($colors[$key] ?? self::DEFAULT_COLORS[$mode][$key]);
                if ($value === null) {
                    throw new DomainException(__('platform_branding.errors.color'));
                }
                $clean[$mode][$key] = $value;
            }
        }
        $gradients = is_array($input['gradients'] ?? null) ? $input['gradients'] : [];
        foreach (self::GRADIENTS as $key) {
            $gradient = is_array($gradients[$key] ?? null) ? $gradients[$key] : [];
            $default = self::DEFAULT_GRADIENTS[$key];
            $from = Color::normalize($gradient['from'] ?? $default['from']);
            $to = Color::normalize($gradient['to'] ?? $default['to']);
            $viaRaw = trim((string) ($gradient['via'] ?? ''));
            $via = $viaRaw === '' ? '' : Color::normalize($viaRaw);
            $angle = filter_var($gradient['angle'] ?? $default['angle'], FILTER_VALIDATE_INT);
            if ($from === null || $to === null || $via === null || ! in_array($angle, self::ANGLES, true)) {
                throw new DomainException(__('platform_branding.errors.gradient'));
            }
            $clean['gradients'][$key] = ['enabled' => (bool) ($gradient['enabled'] ?? false), 'from' => $from, 'via' => $via, 'to' => $to, 'angle' => $angle];
        }

        return $clean;
    }

    /**
     * Stored values over the defaults, each re-checked: a value that is not
     * a colour is dropped rather than emitted.
     *
     * @param  array<string, mixed>  $stored
     * @return array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>}
     */
    public static function hydrate(array $stored): array
    {
        try {
            return self::normalize($stored);
        } catch (DomainException) {
            return self::defaults();
        }
    }

    /**
     * Every semantic token for one theme — the full set, for the preview.
     *
     * @param  array<string, string>  $colors
     * @return array<string, string>
     */
    public static function tokens(array $colors, string $mode, bool $changedOnly = false): array
    {
        $mode = $mode === 'dark' ? 'dark' : 'light';
        $tokens = [];
        foreach (self::COLORS as $key) {
            $value = $colors[$key] ?? self::DEFAULT_COLORS[$mode][$key];
            if ($value === self::DEFAULT_COLORS[$mode][$key]) {
                if (! $changedOnly) {
                    $tokens += self::DEFAULT_TOKENS[$mode][$key];
                }

                continue;
            }
            $tokens += self::derive($key, $value, $colors + self::DEFAULT_COLORS[$mode], $mode);
        }

        return $tokens;
    }

    /** @param array{enabled: bool, from: string, via: string, to: string, angle: int} $gradient */
    public static function gradientCss(array $gradient): string
    {
        $stops = array_filter([$gradient['from'], $gradient['via'], $gradient['to']], static fn (string $color): bool => $color !== '');

        return 'linear-gradient('.$gradient['angle'].'deg, '.implode(', ', $stops).')';
    }

    /**
     * The stylesheet a platform page adds after tokens.css. Empty for the
     * default theme. Every value in it is a validated colour or a number.
     *
     * @param  array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>}  $theme
     */
    public static function css(array $theme): string
    {
        $blocks = [];
        // `:root:not([data-theme="dark"])` rather than `:root`: a later `:root`
        // rule would beat tokens.css's dark block and leak light colours into
        // the dark theme.
        $selectors = ['light' => ':root:not([data-theme="dark"]),[data-theme="light"]', 'dark' => '[data-theme="dark"]'];
        foreach (self::MODES as $mode) {
            $tokens = self::tokens($theme[$mode], $mode, true);
            if ($tokens !== []) {
                $blocks[] = $selectors[$mode].'{'.self::declarations($tokens).'}';
            }
        }
        $gradients = self::gradientTokens($theme['gradients']);
        if ($gradients !== []) {
            $blocks[] = ':root{'.self::declarations($gradients).'}';
        }

        return implode("\n", $blocks);
    }

    /**
     * Inline custom properties for a preview container of one theme.
     *
     * @param  array{light: array<string, string>, dark: array<string, string>, gradients: array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>}  $theme
     */
    public static function inlineStyle(array $theme, string $mode): string
    {
        // The default accent gradient is built from this theme's soft tints;
        // declared here so a nested preview resolves them for its own theme.
        $accent = ['--gradient-accent' => 'linear-gradient(135deg, var(--color-primary-soft), var(--color-accent-soft))'];

        return self::declarations(self::tokens($theme[$mode === 'dark' ? 'dark' : 'light'], $mode) + self::gradientTokens($theme['gradients']) + $accent);
    }

    /**
     * Pairs that fall below WCAG AA with the chosen colours. Reported so the
     * administrator decides; the colours are never adjusted behind their back.
     *
     * @param  array{light: array<string, string>, dark: array<string, string>, gradients: array<string, mixed>}  $theme
     * @return list<array{mode: string, pair: string, ratio: float, minimum: float}>
     */
    public static function contrastWarnings(array $theme): array
    {
        $warnings = [];
        foreach (self::MODES as $mode) {
            $colors = $theme[$mode] + self::DEFAULT_COLORS[$mode];
            // The tokens the page will actually use: hand-tuned for default
            // colours, derived for changed ones.
            $tokens = self::tokens($colors, $mode);
            $strong = $tokens['--color-primary-strong'];
            $checks = [
                'text_background' => [$colors['text'], $colors['background'], 4.5],
                'text_surface' => [$colors['text'], $colors['surface'], 4.5],
                'muted_surface' => [$colors['muted'], $colors['surface'], 4.5],
                'button' => [$tokens['--color-on-primary'], $strong, 4.5],
                'primary_background' => [$colors['primary'], $colors['background'], 3.0],
            ];
            foreach (['success', 'warning', 'danger', 'info'] as $status) {
                // A translucent soft tint (dark theme) is measured as the
                // colour it produces over the page background.
                $soft = $tokens['--color-'.$status.'-soft'];
                $checks['status_'.$status] = [$colors[$status], Color::isHex($soft) ? $soft : Color::mix($colors['background'], $colors[$status], 0.12), 4.5];
            }
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
     * @param  array<string, string>  $colors  the theme's current colours
     * @return array<string, string>
     */
    private static function derive(string $key, string $value, array $colors, string $mode): array
    {
        $dark = $mode === 'dark';
        $background = $colors['background'];
        $surface = $colors['surface'];
        $border = $colors['border'];

        return match ($key) {
            'background' => $dark
                ? ['--color-canvas' => $value, '--color-topbar' => Color::alpha($value, 0.84), '--color-input' => Color::mix($value, $surface, 0.3)]
                : ['--color-canvas' => $value, '--color-topbar' => Color::alpha($value, 0.9), '--color-surface-elevated' => $value, '--color-input' => $value],
            'surface' => $dark
                ? ['--color-surface' => $value, '--color-sidebar' => Color::mix($value, $background, 0.5), '--color-surface-muted' => Color::mix($value, '#ffffff', 0.03), '--color-surface-elevated' => Color::mix($value, '#ffffff', 0.05), '--color-hover' => Color::mix($value, '#ffffff', 0.07), '--color-skeleton' => Color::mix($value, '#ffffff', 0.06), '--color-input-disabled' => Color::mix($value, '#ffffff', 0.02), '--color-disabled' => Color::mix($value, '#ffffff', 0.06)]
                : ['--color-surface' => $value, '--color-sidebar' => $value, '--color-surface-muted' => Color::mix($value, $border, 0.45), '--color-hover' => Color::mix($value, $border, 0.55), '--color-skeleton' => Color::mix($value, $border, 0.8), '--color-input-disabled' => Color::mix($value, $border, 0.5), '--color-disabled' => Color::mix($value, $border, 0.7)],
            'primary' => [
                '--color-primary' => $value,
                '--color-primary-strong' => $strong = self::strong($value, $mode),
                '--color-primary-strong-hover' => $dark ? Color::mix($value, '#ffffff', 0.08) : Color::mix($value, '#000000', 0.18),
                '--color-on-primary' => Color::readableOn($strong),
                '--color-primary-text' => self::readableText($value, $surface, $dark),
                '--color-primary-soft' => $dark ? Color::alpha($value, 0.13) : Color::mix($value, $background, 0.9),
                '--color-primary-border' => $dark ? Color::alpha($value, 0.38) : Color::mix($value, $background, 0.65),
                '--color-focus' => $dark ? Color::mix($value, '#ffffff', 0.1) : $value,
                '--color-selected' => Color::alpha($value, $dark ? 0.14 : 0.11),
            ],
            'secondary' => ['--color-secondary' => $value, '--color-chocolate' => $value],
            'accent' => ['--color-accent' => $value, '--color-accent-soft' => $dark ? Color::alpha($value, 0.09) : Color::mix($value, $background, 0.75)],
            'text' => ['--color-text' => $value, '--color-heading' => $dark ? Color::mix($value, '#ffffff', 0.3) : $value],
            'muted' => $dark
                ? ['--color-text-muted' => $value, '--color-text-subtle' => Color::mix($value, $background, 0.25), '--color-icon' => Color::mix($value, $background, 0.08), '--color-neutral' => $value, '--color-disabled-text' => Color::mix($value, $background, 0.35), '--color-neutral-soft' => Color::alpha($value, 0.1), '--color-neutral-border' => Color::alpha($value, 0.26)]
                : ['--color-text-muted' => $value, '--color-text-subtle' => Color::mix($value, $background, 0.15), '--color-icon' => Color::mix($value, $background, 0.12), '--color-neutral' => $value, '--color-disabled-text' => Color::mix($value, $background, 0.2), '--color-neutral-soft' => Color::mix($value, $background, 0.92), '--color-neutral-border' => Color::mix($value, $background, 0.75)],
            'border' => ['--color-border' => $value, '--color-border-strong' => $dark ? Color::mix($value, '#ffffff', 0.1) : Color::mix($value, $colors['text'], 0.1), '--color-divider' => Color::mix($value, $background, 0.35)],
            default => [
                '--color-'.$key => $value,
                '--color-'.$key.'-soft' => $dark ? Color::alpha($value, 0.12) : Color::mix($value, $background, 0.9),
                '--color-'.$key.'-border' => $dark ? Color::alpha($value, 0.32) : Color::mix($value, $background, 0.7),
            ],
        };
    }

    /** The filled-button background derived from a primary colour. */
    private static function strong(string $primary, string $mode): string
    {
        return $mode === 'dark' ? Color::mix($primary, '#000000', 0.05) : Color::mix($primary, '#000000', 0.08);
    }

    /**
     * The primary colour as link/label text: moved toward black (light) or
     * white (dark) only as far as it takes to read at 4.5:1 on the surface.
     */
    private static function readableText(string $primary, string $surface, bool $dark): string
    {
        for ($weight = 0.0; $weight <= 0.7; $weight += 0.05) {
            $candidate = Color::mix($primary, $dark ? '#ffffff' : '#000000', $weight);
            if (Color::contrast($candidate, $surface) >= 4.5) {
                return $candidate;
            }
        }

        return Color::mix($primary, $dark ? '#ffffff' : '#000000', 0.7);
    }

    /**
     * @param  array<string, array{enabled: bool, from: string, via: string, to: string, angle: int}>  $gradients
     * @return array<string, string>
     */
    private static function gradientTokens(array $gradients): array
    {
        $tokens = [];
        foreach (self::GRADIENTS as $key) {
            if (($gradients[$key]['enabled'] ?? false) === true) {
                $tokens['--gradient-'.$key] = self::gradientCss($gradients[$key]);
                if ($key === 'cta') {
                    // Hover darkens the same gradient slightly.
                    $tokens['--gradient-cta-hover'] = 'linear-gradient(rgb(0 0 0 / .08), rgb(0 0 0 / .08)), '.self::gradientCss($gradients[$key]);
                }
            }
        }

        return $tokens;
    }

    /** @param array<string, string> $tokens */
    private static function declarations(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $name => $value) {
            $out .= $name.':'.$value.';';
        }

        return $out;
    }
}
