<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

use App\Kernel\Platform\Branding\Color;

/**
 * A center's own customer-facing brand as STRUCTURED values.
 *
 * Separate from Meta Style's platform branding (PlatformBranding, control
 * plane): this lives in the center's own database and is read only by the
 * center's own public surfaces. Nothing here accepts CSS — a colour is
 * `#rrggbb`, a gradient is two or three such colours and an angle from a fixed
 * list, a radius or style is a key. So a stored brand can never carry a
 * declaration, a url() or a closing tag into a guest page.
 *
 * Logos and the favicon are media uuids from the center's brand library.
 */
final class CenterBrand
{
    public const COLORS = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'muted', 'border'];

    public const SCHEMES = ['light', 'auto', 'dark'];

    public const GRADIENTS = ['brand', 'hero', 'accent'];

    public const ANGLES = [0, 45, 90, 135, 180, 225, 270, 315];

    public const RADII = ['square', 'soft', 'rounded', 'pill'];

    public const BUTTON_STYLES = ['solid', 'gradient', 'outline'];

    public const CARD_STYLES = ['flat', 'bordered', 'elevated'];

    public const ASSETS = ['logo_light', 'logo_dark', 'favicon'];

    public const DEFAULT_COLORS = [
        'light' => [
            'primary' => '#8a4b5a', 'secondary' => '#2f2a2b', 'accent' => '#c9a27e', 'background' => '#ffffff',
            'surface' => '#f8f4f2', 'text' => '#231f20', 'muted' => '#6b6163', 'border' => '#e8e0dc',
        ],
        'dark' => [
            'primary' => '#d99aa7', 'secondary' => '#e8dcd6', 'accent' => '#d9b48e', 'background' => '#121012',
            'surface' => '#1c191b', 'text' => '#f3eeec', 'muted' => '#b3a9a8', 'border' => '#302b2e',
        ],
    ];

    public const DEFAULT_GRADIENTS = [
        'brand' => ['from' => '#8a4b5a', 'via' => '', 'to' => '#c9a27e', 'angle' => 135],
        'hero' => ['from' => '#2f2a2b', 'via' => '#5b3a44', 'to' => '#8a4b5a', 'angle' => 135],
        'accent' => ['from' => '#f8f4f2', 'via' => '', 'to' => '#f1e3da', 'angle' => 90],
    ];

    /**
     * @return array{light: array<string, string>, dark: array<string, string>, scheme: string, gradients: array<string, array{from: string, via: string, to: string, angle: int}>, radius: string, button_style: string, card_style: string, logo_light: string, logo_dark: string, favicon: string}
     */
    public static function defaults(): array
    {
        return [
            'light' => self::DEFAULT_COLORS['light'],
            'dark' => self::DEFAULT_COLORS['dark'],
            'scheme' => 'light',
            'gradients' => self::DEFAULT_GRADIENTS,
            'radius' => 'rounded',
            'button_style' => 'solid',
            'card_style' => 'bordered',
            'logo_light' => '',
            'logo_dark' => '',
            'favicon' => '',
        ];
    }

    /**
     * Submitted values validated to the allow-listed shape. Throws on the
     * first value that is not a colour, an offered angle or a listed option.
     * Asset slots are kept as they are: they change only through the upload
     * and remove actions, never through the colour form.
     *
     * @param  array<string, mixed>  $input
     * @return array{light: array<string, string>, dark: array<string, string>, scheme: string, gradients: array<string, array{from: string, via: string, to: string, angle: int}>, radius: string, button_style: string, card_style: string, logo_light: string, logo_dark: string, favicon: string}
     *
     * @throws InvalidSiteContent
     */
    public static function normalize(array $input): array
    {
        $clean = self::defaults();

        foreach (['light', 'dark'] as $mode) {
            $colors = is_array($input[$mode] ?? null) ? $input[$mode] : [];
            foreach (self::COLORS as $key) {
                $value = Color::normalize($colors[$key] ?? self::DEFAULT_COLORS[$mode][$key]);
                if ($value === null) {
                    throw new InvalidSiteContent('color', $mode.'.'.$key);
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
                throw new InvalidSiteContent('gradient', 'gradients.'.$key);
            }
            $clean['gradients'][$key] = ['from' => $from, 'via' => $via, 'to' => $to, 'angle' => $angle];
        }

        foreach (['scheme' => self::SCHEMES, 'radius' => self::RADII, 'button_style' => self::BUTTON_STYLES, 'card_style' => self::CARD_STYLES] as $key => $allowed) {
            $value = (string) ($input[$key] ?? $clean[$key]);
            if (! in_array($value, $allowed, true)) {
                throw new InvalidSiteContent('presentation', $key);
            }
            $clean[$key] = $value;
        }

        foreach (self::ASSETS as $slot) {
            $value = $input[$slot] ?? '';
            $clean[$slot] = is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1 ? $value : '';
        }

        return $clean;
    }

    /**
     * Stored values over the defaults. A stored value that no longer passes
     * is replaced by the default rather than rendered.
     *
     * @param  array<string, mixed>  $stored
     * @return array{light: array<string, string>, dark: array<string, string>, scheme: string, gradients: array<string, array{from: string, via: string, to: string, angle: int}>, radius: string, button_style: string, card_style: string, logo_light: string, logo_dark: string, favicon: string}
     */
    public static function hydrate(array $stored): array
    {
        try {
            return self::normalize($stored);
        } catch (InvalidSiteContent) {
            $assets = array_intersect_key($stored, array_flip(self::ASSETS));

            return self::normalize($assets);
        }
    }

    /**
     * The design values only (no asset slots), to compare a form with what is
     * saved, or to reset.
     *
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    public static function design(array $brand): array
    {
        return array_diff_key($brand, array_flip(self::ASSETS));
    }
}
