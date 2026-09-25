<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Kernel\Platform\Branding\Color;
use App\Modules\Menu\Domain\MenuPresentation;

/**
 * Turns a validated presentation into the CSS custom properties and state
 * classes the public menu renders with.
 *
 * Nothing a center typed ever reaches the stylesheet. Colours are `#rrggbb`
 * (validated by {@see MenuPresentation} or the brand contract and checked again
 * here); everything else is looked up from a code-owned map by an enum value.
 * The gradient is assembled here from those two colours and an angle from a
 * fixed list — there is no gradient string a center can supply.
 *
 * Font stacks carry no quotes on purpose: the values are printed inside a
 * `<style>` element through Blade's escaping, and an escaped quote there would
 * silently invalidate the whole declaration.
 */
final class MenuStyle
{
    private const FONTS = [
        'sans' => 'ui-sans-serif, system-ui, Segoe UI, Tahoma, Noto Sans Arabic, Noto Kufi Arabic, sans-serif',
        'serif' => 'ui-serif, Georgia, Noto Naskh Arabic, Times New Roman, serif',
        'display' => 'Segoe UI Semibold, ui-sans-serif, system-ui, Noto Kufi Arabic, sans-serif',
    ];

    private const RADIUS = ['square' => '0', 'rounded' => '.75rem', 'pill' => '1.5rem'];

    private const GAP = ['compact' => '.6rem', 'comfortable' => '1rem', 'spacious' => '1.5rem'];

    private const SCALE = ['small' => '15px', 'medium' => '16px', 'large' => '17.5px'];

    private const PALETTE = [
        'light' => ['bg' => '#ffffff', 'surface' => '#f7f8fa', 'fg' => '#10131a', 'muted' => '#5b6472', 'line' => '#e6e8ee'],
        'dark' => ['bg' => '#0d0f14', 'surface' => '#151922', 'fg' => '#e8eaf0', 'muted' => '#98a1b0', 'line' => '#242a36'],
    ];

    /**
     * @param  array{primary: string|null, accent: string|null}|array<string, mixed>|null  $brand
     * @return array{vars: array<string, string>, classes: list<string>, dark: bool}
     */
    public function for(MenuPresentation $presentation, ?array $brand = null): array
    {
        $theme = static fn (string $key, string $fallback): string => $presentation->themeValue($key, $fallback);

        $dark = $theme('background', 'light') === 'dark';
        $palette = self::PALETTE[$dark ? 'dark' : 'light'];

        [$primary, $accent] = $this->colours($presentation, $brand);

        $hero = $theme('hero_style', 'plain');
        $angle = in_array($theme('gradient_angle', '135'), ['90', '135', '180'], true) ? $theme('gradient_angle', '135') : '135';

        $heroBackground = match ($hero) {
            'gradient' => sprintf('linear-gradient(%sdeg, %s, %s)', $angle, $primary, $accent),
            'tint' => Color::mix($palette['bg'], $accent, $dark ? 0.18 : 0.1),
            default => 'transparent',
        };
        $heroText = match ($hero) {
            'gradient' => Color::readableOn(Color::mix($primary, $accent, 0.5), '#ffffff', '#10131a'),
            default => $palette['fg'],
        };
        $heroTitle = $hero === 'gradient' ? $heroText : $primary;

        return [
            'vars' => [
                '--primary' => $primary,
                '--accent' => $accent,
                '--on-accent' => Color::readableOn($accent, '#ffffff', '#10131a'),
                '--bg' => $palette['bg'],
                '--surface' => $palette['surface'],
                '--fg' => $palette['fg'],
                '--muted' => $palette['muted'],
                '--line' => $palette['line'],
                '--radius' => self::RADIUS[$theme('corners', 'rounded')] ?? self::RADIUS['rounded'],
                '--gap' => self::GAP[$theme('density', 'comfortable')] ?? self::GAP['comfortable'],
                '--font' => self::FONTS[$theme('font', 'sans')] ?? self::FONTS['sans'],
                '--base-size' => self::SCALE[$theme('type_scale', 'medium')] ?? self::SCALE['medium'],
                '--hero-bg' => $heroBackground,
                '--hero-fg' => $heroText,
                '--hero-title' => $heroTitle,
            ],
            'classes' => [
                'layout-'.$this->choice($theme('layout', 'grid'), ['stacked', 'grid', 'compact'], 'grid'),
                'cards-'.$this->choice($theme('card_style', 'flat'), ['flat', 'bordered', 'elevated'], 'flat'),
                'ratio-'.$this->choice($theme('image_ratio', 'natural'), ['natural', 'landscape', 'square', 'portrait', 'hidden'], 'natural'),
                'price-'.$this->choice($theme('price_style', 'inline'), ['inline', 'badge', 'below'], 'inline'),
                'cta-'.$this->choice($theme('cta_style', 'solid'), ['solid', 'outline', 'pill'], 'solid'),
                'hero-'.$this->choice($hero, ['plain', 'tint', 'gradient'], 'plain'),
                $dark ? 'theme-dark' : 'theme-light',
            ],
            'dark' => $dark,
        ];
    }

    /**
     * The two colours actually used: the brand's when the menu follows the
     * brand AND the brand has set them, the menu's own otherwise.
     *
     * @param  array<string, mixed>|null  $brand
     * @return array{0: string, 1: string}
     */
    private function colours(MenuPresentation $presentation, ?array $brand): array
    {
        $primary = Color::normalize($presentation->themeValue('primary')) ?? '#111827';
        $accent = Color::normalize($presentation->themeValue('accent')) ?? '#6366f1';

        if ($presentation->themeValue('colors_source', 'menu') === 'brand' && is_array($brand)) {
            $primary = Color::normalize($brand['primary'] ?? null) ?? $primary;
            $accent = Color::normalize($brand['accent'] ?? null) ?? $accent;
        }

        return [$primary, $accent];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
