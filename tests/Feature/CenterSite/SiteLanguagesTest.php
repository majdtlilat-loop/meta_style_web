<?php

declare(strict_types=1);

use App\Modules\CenterSite\Domain\SiteCatalog;
use Illuminate\Support\Arr;

/*
|--------------------------------------------------------------------------
| The site builder and the public site speak English, Arabic and Kurdish
|--------------------------------------------------------------------------
|
| Every key exists in all three languages with the same placeholders, the
| Arabic and Kurdish files are real translations (not English copies), and
| every label the catalog builds a key for at runtime (section types, choice
| options, icons, social networks) exists. A missing dynamic key would print
| its raw path on a customer's page.
|
*/

it('keeps manager_site, center_site and media_upload in exact parity, translated, with matching placeholders', function (string $group): void {
    /** @var array<string, mixed> $english */
    $english = Arr::dot(require lang_path("en/{$group}.php"));

    foreach (['ar', 'ckb'] as $locale) {
        /** @var array<string, mixed> $translated */
        $translated = Arr::dot(require lang_path("{$locale}/{$group}.php"));

        expect(array_keys($translated))->toBe(array_keys($english), "{$locale}/{$group} keys differ from en");

        foreach ($english as $key => $value) {
            expect($translated[$key])->toBeString()->not->toBe('', "{$locale}.{$group}.{$key} is empty");
            preg_match_all('/:([a-z_]+)/', (string) $value, $wanted);
            preg_match_all('/:([a-z_]+)/', (string) $translated[$key], $given);
            expect(array_values(array_unique($given[1])))->toEqualCanonicalizing(array_values(array_unique($wanted[1])), "{$locale}.{$group}.{$key} placeholders");

            // A string that is more than a placeholder must actually be translated.
            if (trim((string) preg_replace('/:[a-z_]+/', '', (string) $value)) !== '') {
                expect($translated[$key] === $value)->toBeFalse("{$locale}.{$group}.{$key} is an English copy");
            }
        }
    }
})->with(['manager_site', 'center_site', 'media_upload']);

it('has a label for every section type, choice, icon and network the catalog offers', function (string $locale): void {
    app()->setLocale($locale);
    $keys = [];
    foreach (SiteCatalog::TYPES as $type => $spec) {
        $keys[] = "manager_site.types.{$type}.label";
        $keys[] = "manager_site.types.{$type}.help";
        $keys[] = "center_site.types.{$type}";
        foreach ($spec['layouts'] as $layout) {
            $keys[] = "manager_site.options.layout.{$layout}";
        }
    }
    $groups = [
        'hero_layout' => SiteCatalog::HERO_LAYOUTS, 'hero_height' => SiteCatalog::HERO_HEIGHTS,
        'alignment' => SiteCatalog::ALIGNMENTS, 'visibility' => SiteCatalog::VISIBILITY,
        'media_position' => SiteCatalog::MEDIA_POSITIONS, 'background' => SiteCatalog::BACKGROUNDS,
        'background_color' => SiteCatalog::BACKGROUND_COLORS, 'gradient' => SiteCatalog::BACKGROUND_GRADIENTS,
        'overlay' => SiteCatalog::OVERLAYS, 'header_style' => SiteCatalog::HEADER_STYLES,
        'header_layout' => SiteCatalog::HEADER_LAYOUTS, 'logo' => SiteCatalog::LOGO_VARIANTS,
        'cta_style' => SiteCatalog::CTA_STYLES, 'link_type' => SiteCatalog::LINK_TYPES, 'page' => SiteCatalog::PAGES,
        'canonical' => SiteCatalog::CANONICAL, 'service_mode' => SiteCatalog::SERVICE_MODES,
        'selection_mode' => SiteCatalog::SELECTION_MODES, 'service_link' => SiteCatalog::SERVICE_LINKS,
        'map_mode' => SiteCatalog::MAP_MODES, 'network' => array_keys(SiteCatalog::SOCIAL_NETWORKS),
    ];
    foreach ($groups as $group => $values) {
        foreach ($values as $value) {
            $keys[] = "manager_site.options.{$group}.{$value}";
        }
    }
    foreach (SiteCatalog::ICONS as $icon) {
        $keys[] = "manager_site.icons.{$icon}";
    }
    foreach (array_keys(SiteCatalog::SOCIAL_NETWORKS) as $network) {
        $keys[] = "center_site.social.{$network}";
    }

    $missing = array_values(array_filter($keys, static function (string $key): bool {
        $value = __($key);

        return ! is_string($value) || $value === $key;
    }));

    expect($missing)->toBe([]);
})->with(['en', 'ar', 'ckb']);
