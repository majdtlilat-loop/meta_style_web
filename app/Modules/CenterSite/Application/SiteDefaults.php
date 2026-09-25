<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\CenterSite\Domain\SiteContent;

/**
 * The page a center has before anyone opens the builder.
 *
 * Only sections backed by the center's REAL data are switched on — services,
 * rating, hours, booking, contact, map — each of which renders nothing when
 * the data is not there yet. Nothing claims anything about the center that it
 * did not say itself: the About section starts switched off with an empty body
 * for the owner to write. Copy is taken from the `center_site` translations in
 * every platform language, so the default reads correctly whatever the
 * center's primary language is.
 */
final class SiteDefaults
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly LanguageRegistry $languages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function content(): array
    {
        $name = (string) ($this->tenants->tenant()->name ?? '');
        $content = SiteContent::skeleton();

        $content['header']['cta'] = ['enabled' => true, 'label' => $this->text('book_now'), 'link_type' => 'page', 'target' => 'booking', 'style' => 'primary', 'new_tab' => false];

        $content['hero']['eyebrow'] = $this->text('hero_eyebrow', [], 60);
        $content['hero']['title'] = $this->text('hero_title', ['name' => $name], 140);
        $content['hero']['body'] = $this->text('hero_body', [], 400);
        $content['hero']['primary_cta'] = ['enabled' => true, 'label' => $this->text('book_now'), 'link_type' => 'page', 'target' => 'booking', 'style' => 'primary', 'new_tab' => false];
        $content['hero']['secondary_cta'] = ['enabled' => true, 'label' => $this->text('view_services'), 'link_type' => 'section', 'target' => 'services', 'style' => 'secondary', 'new_tab' => false];

        $sections = [
            'about' => ['about', 'about', false],
            'services' => ['services', 'services', true],
            'rating' => ['rating', 'rating', true],
            'hours' => ['hours', 'hours', true],
            'booking_cta' => ['booking_cta', 'book', true],
            'contact' => ['contact', 'contact', true],
            'map' => ['map', 'location', true],
        ];
        foreach ($sections as $id => [$type, $anchor, $enabled]) {
            $section = SiteContent::blankSection($type);
            $section['anchor'] = $anchor;
            $section['enabled'] = $enabled;
            $section['title'] = $this->text('sections.'.$type.'.title', [], 140);
            $section['subtitle'] = $this->text('sections.'.$type.'.subtitle', [], 200);
            if ($type === 'about') {
                $section['items'] = [];
            }
            if ($type === 'booking_cta') {
                $section['cta'] = ['enabled' => true, 'label' => $this->text('book_now'), 'link_type' => 'page', 'target' => 'booking', 'style' => 'primary', 'new_tab' => false];
            }
            if ($type === 'services') {
                $section['cta'] = ['enabled' => true, 'label' => $this->text('all_services'), 'link_type' => 'page', 'target' => 'list', 'style' => 'outline', 'new_tab' => false];
            }
            $content['sections'][$id] = $section;
        }
        $content['section_order'] = array_keys($sections);

        $content['navigation'] = [
            $this->link('nav_services', 'section', 'services'),
            $this->link('nav_hours', 'section', 'hours'),
            $this->link('nav_contact', 'section', 'contact'),
        ];

        $content['footer']['copyright'] = $this->text('copyright', ['name' => $name], 160);
        $content['footer']['navigation'] = [
            $this->link('nav_services', 'page', 'list'),
            $this->link('nav_booking', 'page', 'booking'),
        ];

        $content['seo']['title'] = $this->text('seo_title', ['name' => $name], 70);
        $content['seo']['description'] = $this->text('seo_description', ['name' => $name], 170);

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function link(string $key, string $type, string $target): array
    {
        return ['id' => SiteContent::newId(), 'enabled' => true, 'label' => $this->text($key), 'link_type' => $type, 'target' => $target, 'new_tab' => false];
    }

    /**
     * One default text in every platform language.
     *
     * @param  array<string, string>  $replace
     * @return array<string, string>
     */
    private function text(string $key, array $replace = [], int $max = 40): array
    {
        $out = [];
        foreach ($this->languages->supported() as $locale) {
            $value = (string) __('center_site.defaults.'.$key, $replace, $locale);
            if ($value !== '' && $value !== 'center_site.defaults.'.$key) {
                $out[$locale] = mb_substr($value, 0, $max);
            }
        }

        return $out;
    }
}
