<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

/**
 * The allow-listed shape of a center's public landing page.
 *
 * Site content is DATA, never markup. Every text is plain text per language;
 * every link is a section anchor on this page, one of the center's own pages
 * by key, or an https address; every image and video is a uuid of media this
 * center uploaded; every presentation choice comes from SiteCatalog. A crafted
 * Livewire payload cannot smuggle HTML, a `javascript:` URL, CSS, another
 * center's file or another center's employee into the page.
 *
 * `normalize()` is the security boundary and is STRICT: an unknown key, type
 * or value is refused with a path, never silently dropped — the owner must see
 * that what they entered was not accepted. `hydrate()` is its tolerant twin
 * for stored content: it brings an older shape up to date and drops what the
 * current catalog no longer knows, so a read never fails.
 *
 * The PRIMARY content language is required wherever text is required — the
 * center's own primary, not English.
 */
final class SiteContent
{
    private const ID = '/^[a-z0-9]{4,16}$/';

    private const SECTION_ID = '/^[a-z][a-z0-9_]{1,40}$/';

    private const ANCHOR = '/^[a-z0-9][a-z0-9-]{0,40}$/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** E.164: the builder turns country + number into one canonical value (PhoneNumber::fromParts). */
    private const PHONE = '/^\+[1-9][0-9]{7,14}$/';

    public function __construct(private readonly SiteContext $context) {}

    // ── Shapes ──────────────────────────────────────────────────────────────

    /**
     * The complete shape with blank values. Every stored or submitted document
     * is read against this, key by key.
     *
     * @return array<string, mixed>
     */
    public static function skeleton(): array
    {
        return [
            'header' => [
                'logo' => 'auto', 'show_name' => true, 'style' => 'solid', 'layout' => 'inline', 'sticky' => true,
                'show_language_switch' => true, 'cta' => self::blankCta(),
            ],
            'navigation' => [],
            'hero' => [
                'enabled' => true, 'layout' => 'split', 'alignment' => 'start', 'height' => 'auto',
                'eyebrow' => [], 'title' => [], 'subtitle' => [], 'body' => [],
                'primary_cta' => self::blankCta(), 'secondary_cta' => self::blankCta('secondary'),
                'image' => '', 'image_alt' => [], 'video' => '', 'poster' => '',
                'background' => self::blankBackground('soft'),
            ],
            'section_order' => [],
            'sections' => [],
            'footer' => [
                'enabled' => true, 'show_logo' => true, 'description' => [], 'address' => [],
                'contact_phone' => '', 'contact_whatsapp' => '', 'contact_email' => '',
                'show_hours' => true, 'show_booking_cta' => true,
                'social' => [], 'navigation' => [], 'legal' => [], 'copyright' => [],
            ],
            'seo' => [
                'title' => [], 'description' => [], 'og_title' => [], 'og_description' => [],
                'og_image' => '', 'og_image_alt' => [], 'index' => true, 'follow' => true, 'canonical' => 'self',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function blankCta(string $style = 'primary'): array
    {
        return ['enabled' => false, 'label' => [], 'link_type' => 'page', 'target' => 'booking', 'style' => $style, 'new_tab' => false];
    }

    /** @return array<string, mixed> */
    public static function blankBackground(string $type = 'none'): array
    {
        return ['type' => $type, 'color' => 'primary', 'gradient' => 'brand', 'image' => '', 'video' => '', 'poster' => '', 'overlay' => 'soft'];
    }

    /** @return array<string, mixed> */
    public static function blankLink(): array
    {
        return ['id' => self::newId(), 'enabled' => true, 'label' => [], 'link_type' => 'section', 'target' => '', 'new_tab' => false];
    }

    /** @return array<string, mixed> */
    public static function blankSocial(): array
    {
        return ['id' => self::newId(), 'enabled' => true, 'network' => 'instagram', 'url' => ''];
    }

    /** @return array<string, mixed> */
    public static function blankItem(string $kind = 'feature'): array
    {
        return [
            'id' => self::newId(), 'enabled' => true, 'title' => [], 'body' => [],
            'icon' => $kind === 'feature' ? 'sparkles' : '', 'image' => '', 'image_alt' => [], 'employee_uuid' => '',
        ];
    }

    /**
     * A fresh section of a type with that type's defaults.
     *
     * @return array<string, mixed>
     */
    public static function blankSection(string $type): array
    {
        if (! SiteCatalog::isType($type)) {
            throw new InvalidSiteContent('section_type', 'sections');
        }
        $spec = SiteCatalog::type($type);
        $section = [
            'type' => $type, 'enabled' => true, 'anchor' => '', 'visibility' => 'all',
            'layout' => $spec['layouts'][0], 'columns' => 3, 'alignment' => in_array($type, ['booking_cta', 'rating'], true) ? 'center' : 'start',
            'icon' => $spec['icon'] === 'info' ? 'sparkles' : (in_array($spec['icon'], SiteCatalog::ICONS, true) ? $spec['icon'] : 'sparkles'),
            'eyebrow' => [], 'title' => [], 'subtitle' => [], 'body' => [],
            'image' => '', 'image_alt' => [], 'video' => '', 'poster' => '', 'media_position' => 'end',
            'cta' => self::blankCta(),
            'background' => self::blankBackground($type === 'booking_cta' ? 'gradient' : 'none'),
            'items' => [],
            'source' => SiteCatalog::sourceDefaults($type),
        ];
        if ($type === 'booking_cta') {
            $section['cta']['enabled'] = true;
        }
        if (in_array($spec['items'], ['feature', 'faq'], true)) {
            $section['items'] = [self::blankItem((string) $spec['items'])];
        }

        return $section;
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * Stored content brought up to the current shape. Never throws: unknown
     * section types and keys are dropped, missing keys filled with blanks,
     * missing item ids assigned, and the order repaired.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function hydrate(array $stored): array
    {
        $skeleton = self::skeleton();
        $content = self::fit($skeleton, $stored);

        foreach (['navigation', 'footer.navigation', 'footer.legal'] as $list) {
            data_set($content, $list, self::fitList(data_get($stored, $list), self::blankLink()));
        }
        $content['footer']['social'] = self::fitList($stored['footer']['social'] ?? null, self::blankSocial());

        $sections = [];
        foreach ((array) ($stored['sections'] ?? []) as $id => $section) {
            if (! is_string($id) || preg_match(self::SECTION_ID, $id) !== 1 || ! is_array($section)) {
                continue;
            }
            $type = (string) ($section['type'] ?? '');
            if (! SiteCatalog::isType($type)) {
                continue;
            }
            $blank = self::blankSection($type);
            $blank['items'] = [];
            $fitted = self::fit($blank, $section);
            $fitted['type'] = $type;
            $fitted['items'] = self::fitList($section['items'] ?? null, self::blankItem());
            $fitted['source'] = self::fit(SiteCatalog::sourceDefaults($type), is_array($section['source'] ?? null) ? $section['source'] : []);
            $sections[$id] = $fitted;
        }
        $order = [];
        foreach ((array) ($stored['section_order'] ?? []) as $id) {
            if (is_string($id) && isset($sections[$id]) && ! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        foreach (array_keys($sections) as $id) {
            if (! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        $content['sections'] = $sections;
        $content['section_order'] = $order;

        return $content;
    }

    /**
     * Every text of every item and section, validated against the catalog and
     * this center. Returns the clean document; throws on the first refusal.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws InvalidSiteContent
     */
    public function normalize(array $input): array
    {
        $this->keys($input, self::skeleton(), '');

        $sections = $this->arrayAt($input, 'sections', '');
        if (count($sections) > SiteCatalog::MAX_SECTIONS) {
            throw new InvalidSiteContent('too_many_sections', 'sections', ['max' => SiteCatalog::MAX_SECTIONS]);
        }

        $clean = [
            'header' => $this->header($this->arrayAt($input, 'header', '')),
            'navigation' => $this->links($input['navigation'] ?? [], 'navigation', SiteCatalog::MAX_NAVIGATION),
            'hero' => $this->hero($this->arrayAt($input, 'hero', '')),
            'section_order' => [],
            'sections' => [],
            'footer' => $this->footer($this->arrayAt($input, 'footer', '')),
            'seo' => $this->seo($this->arrayAt($input, 'seo', '')),
        ];

        $anchors = [];
        foreach ($sections as $id => $section) {
            $path = 'sections.'.$id;
            if (! is_string($id) || preg_match(self::SECTION_ID, $id) !== 1 || ! is_array($section)) {
                throw new InvalidSiteContent('section_id', 'sections');
            }
            $normalized = $this->section($section, $path);
            if ($normalized['anchor'] !== '') {
                if (in_array($normalized['anchor'], $anchors, true)) {
                    throw new InvalidSiteContent('anchor_taken', $path.'.anchor', ['anchor' => $normalized['anchor']]);
                }
                $anchors[] = $normalized['anchor'];
            }
            $clean['sections'][$id] = $normalized;
        }

        $order = is_array($input['section_order'] ?? null) ? $input['section_order'] : [];
        foreach ($order as $id) {
            if (is_string($id) && isset($clean['sections'][$id]) && ! in_array($id, $clean['section_order'], true)) {
                $clean['section_order'][] = $id;
            }
        }
        foreach (array_keys($clean['sections']) as $id) {
            if (! in_array($id, $clean['section_order'], true)) {
                $clean['section_order'][] = $id;
            }
        }

        return $clean;
    }

    // ── Parts ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function header(array $header): array
    {
        $this->keys($header, self::skeleton()['header'], 'header');

        return [
            'logo' => $this->choice($header['logo'] ?? 'auto', SiteCatalog::LOGO_VARIANTS, 'header.logo'),
            'show_name' => $this->bool($header['show_name'] ?? true, 'header.show_name'),
            'style' => $this->choice($header['style'] ?? 'solid', SiteCatalog::HEADER_STYLES, 'header.style'),
            'layout' => $this->choice($header['layout'] ?? 'inline', SiteCatalog::HEADER_LAYOUTS, 'header.layout'),
            'sticky' => $this->bool($header['sticky'] ?? true, 'header.sticky'),
            'show_language_switch' => $this->bool($header['show_language_switch'] ?? true, 'header.show_language_switch'),
            'cta' => $this->cta($header['cta'] ?? [], 'header.cta'),
        ];
    }

    /**
     * @param  array<string, mixed>  $hero
     * @return array<string, mixed>
     */
    private function hero(array $hero): array
    {
        $this->keys($hero, self::skeleton()['hero'], 'hero');
        $enabled = $this->bool($hero['enabled'] ?? true, 'hero.enabled');

        return [
            'enabled' => $enabled,
            'layout' => $this->choice($hero['layout'] ?? 'split', SiteCatalog::HERO_LAYOUTS, 'hero.layout'),
            'alignment' => $this->choice($hero['alignment'] ?? 'start', SiteCatalog::ALIGNMENTS, 'hero.alignment'),
            'height' => $this->choice($hero['height'] ?? 'auto', SiteCatalog::HERO_HEIGHTS, 'hero.height'),
            'eyebrow' => $this->localized($hero['eyebrow'] ?? [], SiteCatalog::LIMITS['eyebrow'], 'hero.eyebrow'),
            'title' => $this->localized($hero['title'] ?? [], SiteCatalog::LIMITS['title'], 'hero.title', $enabled),
            'subtitle' => $this->localized($hero['subtitle'] ?? [], SiteCatalog::LIMITS['subtitle'], 'hero.subtitle'),
            'body' => $this->localized($hero['body'] ?? [], SiteCatalog::LIMITS['hero_body'], 'hero.body'),
            'primary_cta' => $this->cta($hero['primary_cta'] ?? [], 'hero.primary_cta'),
            'secondary_cta' => $this->cta($hero['secondary_cta'] ?? [], 'hero.secondary_cta'),
            'image' => $this->media($hero['image'] ?? '', 'image', 'hero.image'),
            'image_alt' => $this->localized($hero['image_alt'] ?? [], SiteCatalog::LIMITS['alt'], 'hero.image_alt'),
            'video' => $this->media($hero['video'] ?? '', 'video', 'hero.video'),
            'poster' => $this->media($hero['poster'] ?? '', 'image', 'hero.poster'),
            'background' => $this->background($hero['background'] ?? [], 'hero.background'),
        ];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function section(array $section, string $path): array
    {
        $type = (string) ($section['type'] ?? '');
        if (! SiteCatalog::isType($type)) {
            throw new InvalidSiteContent('section_type', $path.'.type');
        }
        $spec = SiteCatalog::type($type);
        $this->keys($section, self::blankSection($type), $path);

        $anchor = trim((string) ($section['anchor'] ?? ''));
        if ($anchor !== '' && preg_match(self::ANCHOR, $anchor) !== 1) {
            throw new InvalidSiteContent('anchor', $path.'.anchor');
        }

        $items = $section['items'] ?? [];
        if (! is_array($items) || ($spec['items'] === null && $items !== []) || count($items) > $spec['max']) {
            throw new InvalidSiteContent('item_limit', $path.'.items', ['max' => $spec['max']]);
        }

        return [
            'type' => $type,
            'enabled' => $this->bool($section['enabled'] ?? true, $path.'.enabled'),
            'anchor' => $anchor,
            'visibility' => $this->choice($section['visibility'] ?? 'all', SiteCatalog::VISIBILITY, $path.'.visibility'),
            'layout' => $this->choice($section['layout'] ?? $spec['layouts'][0], $spec['layouts'], $path.'.layout'),
            'columns' => $this->int($section['columns'] ?? 3, $path.'.columns', SiteCatalog::LAYOUT_COLUMNS),
            'alignment' => $this->choice($section['alignment'] ?? 'start', SiteCatalog::ALIGNMENTS, $path.'.alignment'),
            'icon' => $this->choice($section['icon'] ?? 'sparkles', SiteCatalog::ICONS, $path.'.icon'),
            'eyebrow' => $this->localized($section['eyebrow'] ?? [], SiteCatalog::LIMITS['eyebrow'], $path.'.eyebrow'),
            'title' => $this->localized($section['title'] ?? [], SiteCatalog::LIMITS['title'], $path.'.title'),
            'subtitle' => $this->localized($section['subtitle'] ?? [], SiteCatalog::LIMITS['subtitle'], $path.'.subtitle'),
            'body' => $this->localized($section['body'] ?? [], SiteCatalog::LIMITS['body'], $path.'.body'),
            'image' => $this->media($section['image'] ?? '', 'image', $path.'.image'),
            'image_alt' => $this->localized($section['image_alt'] ?? [], SiteCatalog::LIMITS['alt'], $path.'.image_alt'),
            'video' => $this->media($section['video'] ?? '', 'video', $path.'.video'),
            'poster' => $this->media($section['poster'] ?? '', 'image', $path.'.poster'),
            'media_position' => $this->choice($section['media_position'] ?? 'end', SiteCatalog::MEDIA_POSITIONS, $path.'.media_position'),
            'cta' => $this->cta($section['cta'] ?? [], $path.'.cta'),
            'background' => $this->background($section['background'] ?? [], $path.'.background'),
            'items' => $this->items($items, (string) $spec['items'], $path.'.items'),
            'source' => $this->source($type, $section['source'] ?? [], $path.'.source'),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function items(array $items, string $kind, string $path): array
    {
        $clean = [];
        $ids = [];
        foreach (array_values($items) as $index => $item) {
            $at = $path.'.'.$index;
            if (! is_array($item)) {
                throw new InvalidSiteContent('presentation', $at);
            }
            $this->keys($item, self::blankItem(), $at);
            $id = $this->itemId($item['id'] ?? '', $at, $ids);
            $employee = trim((string) ($item['employee_uuid'] ?? ''));
            $image = $this->media($item['image'] ?? '', 'image', $at.'.image');

            if ($kind === 'team') {
                $this->reference('employees', $employee, $at.'.employee_uuid', true);
            } elseif ($employee !== '') {
                throw new InvalidSiteContent('presentation', $at.'.employee_uuid');
            }
            if ($kind === 'gallery' && $image === '') {
                throw new InvalidSiteContent('image_required', $at.'.image');
            }

            $clean[] = [
                'id' => $id,
                'enabled' => $this->bool($item['enabled'] ?? true, $at.'.enabled'),
                'title' => $this->localized($item['title'] ?? [], SiteCatalog::LIMITS['item_title'], $at.'.title', $kind === 'faq'),
                'body' => $this->localized($item['body'] ?? [], SiteCatalog::LIMITS['item_body'], $at.'.body', $kind === 'faq'),
                'icon' => ($item['icon'] ?? '') === '' && $kind !== 'feature' ? '' : $this->choice($item['icon'] ?? 'sparkles', SiteCatalog::ICONS, $at.'.icon'),
                'image' => $image,
                'image_alt' => $this->localized($item['image_alt'] ?? [], SiteCatalog::LIMITS['alt'], $at.'.image_alt'),
                'employee_uuid' => $employee,
            ];
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function source(string $type, mixed $value, string $path): array
    {
        $defaults = SiteCatalog::sourceDefaults($type);
        $value = is_array($value) ? $value : [];
        $this->keys($value, $defaults, $path);

        $clean = [];
        foreach ($defaults as $key => $default) {
            $raw = $value[$key] ?? $default;
            $at = $path.'.'.$key;
            $clean[$key] = match (true) {
                is_bool($default) => $this->bool($raw, $at),
                $key === 'mode' && $type === 'services' => $this->choice($raw, SiteCatalog::SERVICE_MODES, $at),
                $key === 'mode' && $type === 'map' => $this->choice($raw, SiteCatalog::MAP_MODES, $at),
                $key === 'mode' => $this->choice($raw, SiteCatalog::SELECTION_MODES, $at),
                $key === 'link' => $this->choice($raw, SiteCatalog::SERVICE_LINKS, $at),
                $key === 'limit' => $this->int($raw, $at, range(1, SiteCatalog::MAX_SELECTED)),
                $key === 'zoom' => $this->int($raw, $at, range(10, 18)),
                $key === 'branch_uuid' => $this->reference('branches', trim((string) $raw), $at, false),
                $key === 'service_uuids' => $this->uuids($raw, 'services', $at),
                $key === 'category_uuids' => $this->uuids($raw, 'categories', $at),
                $key === 'branch_uuids' => $this->uuids($raw, 'branches', $at),
                $key === 'uuids' => $this->uuids($raw, $type, $at),
                default => throw new InvalidSiteContent('unknown_field', $at),
            };
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $footer
     * @return array<string, mixed>
     */
    private function footer(array $footer): array
    {
        $this->keys($footer, self::skeleton()['footer'], 'footer');

        return [
            'enabled' => $this->bool($footer['enabled'] ?? true, 'footer.enabled'),
            'show_logo' => $this->bool($footer['show_logo'] ?? true, 'footer.show_logo'),
            'description' => $this->localized($footer['description'] ?? [], SiteCatalog::LIMITS['footer_description'], 'footer.description'),
            'address' => $this->localized($footer['address'] ?? [], SiteCatalog::LIMITS['address'], 'footer.address'),
            'contact_phone' => $this->phone($footer['contact_phone'] ?? '', 'footer.contact_phone'),
            'contact_whatsapp' => $this->phone($footer['contact_whatsapp'] ?? '', 'footer.contact_whatsapp'),
            'contact_email' => $this->email($footer['contact_email'] ?? '', 'footer.contact_email'),
            'show_hours' => $this->bool($footer['show_hours'] ?? true, 'footer.show_hours'),
            'show_booking_cta' => $this->bool($footer['show_booking_cta'] ?? true, 'footer.show_booking_cta'),
            'social' => $this->social($footer['social'] ?? []),
            'navigation' => $this->links($footer['navigation'] ?? [], 'footer.navigation', SiteCatalog::MAX_FOOTER_LINKS),
            'legal' => $this->links($footer['legal'] ?? [], 'footer.legal', SiteCatalog::MAX_LEGAL_LINKS),
            'copyright' => $this->localized($footer['copyright'] ?? [], SiteCatalog::LIMITS['copyright'], 'footer.copyright'),
        ];
    }

    /**
     * @param  array<string, mixed>  $seo
     * @return array<string, mixed>
     */
    private function seo(array $seo): array
    {
        $this->keys($seo, self::skeleton()['seo'], 'seo');

        return [
            'title' => $this->localized($seo['title'] ?? [], SiteCatalog::LIMITS['seo_title'], 'seo.title', true),
            'description' => $this->localized($seo['description'] ?? [], SiteCatalog::LIMITS['seo_description'], 'seo.description', true),
            'og_title' => $this->localized($seo['og_title'] ?? [], SiteCatalog::LIMITS['og_title'], 'seo.og_title'),
            'og_description' => $this->localized($seo['og_description'] ?? [], SiteCatalog::LIMITS['og_description'], 'seo.og_description'),
            'og_image' => $this->media($seo['og_image'] ?? '', 'image', 'seo.og_image'),
            'og_image_alt' => $this->localized($seo['og_image_alt'] ?? [], SiteCatalog::LIMITS['alt'], 'seo.og_image_alt'),
            'index' => $this->bool($seo['index'] ?? true, 'seo.index'),
            'follow' => $this->bool($seo['follow'] ?? true, 'seo.follow'),
            'canonical' => $this->choice($seo['canonical'] ?? 'self', SiteCatalog::CANONICAL, 'seo.canonical'),
        ];
    }

    // ── Repeated structures ─────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function cta(mixed $value, string $path): array
    {
        $value = is_array($value) ? $value : [];
        $this->keys($value, self::blankCta(), $path);
        $enabled = $this->bool($value['enabled'] ?? false, $path.'.enabled');
        $type = $this->choice($value['link_type'] ?? 'page', SiteCatalog::LINK_TYPES, $path.'.link_type');

        return [
            'enabled' => $enabled,
            'label' => $this->localized($value['label'] ?? [], SiteCatalog::LIMITS['label'], $path.'.label', $enabled),
            'link_type' => $type,
            'target' => $this->target($type, $value['target'] ?? '', $path.'.target', $enabled),
            'style' => $this->choice($value['style'] ?? 'primary', SiteCatalog::CTA_STYLES, $path.'.style'),
            'new_tab' => $this->bool($value['new_tab'] ?? false, $path.'.new_tab'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function links(mixed $value, string $path, int $max): array
    {
        if (! is_array($value) || count($value) > $max) {
            throw new InvalidSiteContent('link_limit', $path, ['max' => $max]);
        }
        $clean = [];
        $ids = [];
        foreach (array_values($value) as $index => $link) {
            $at = $path.'.'.$index;
            if (! is_array($link)) {
                throw new InvalidSiteContent('presentation', $at);
            }
            $this->keys($link, self::blankLink(), $at);
            $type = $this->choice($link['link_type'] ?? 'section', SiteCatalog::LINK_TYPES, $at.'.link_type');
            $clean[] = [
                'id' => $this->itemId($link['id'] ?? '', $at, $ids),
                'enabled' => $this->bool($link['enabled'] ?? true, $at.'.enabled'),
                'label' => $this->localized($link['label'] ?? [], SiteCatalog::LIMITS['label'], $at.'.label', true),
                'link_type' => $type,
                'target' => $this->target($type, $link['target'] ?? '', $at.'.target', true),
                'new_tab' => $this->bool($link['new_tab'] ?? false, $at.'.new_tab'),
            ];
        }

        return $clean;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function social(mixed $value): array
    {
        if (! is_array($value) || count($value) > SiteCatalog::MAX_SOCIAL) {
            throw new InvalidSiteContent('link_limit', 'footer.social', ['max' => SiteCatalog::MAX_SOCIAL]);
        }
        $clean = [];
        $ids = [];
        foreach (array_values($value) as $index => $link) {
            $at = 'footer.social.'.$index;
            if (! is_array($link)) {
                throw new InvalidSiteContent('presentation', $at);
            }
            $this->keys($link, self::blankSocial(), $at);
            $network = $this->choice($link['network'] ?? '', array_keys(SiteCatalog::SOCIAL_NETWORKS), $at.'.network');
            $url = $this->https($link['url'] ?? '', $at.'.url', true);
            $hosts = SiteCatalog::SOCIAL_NETWORKS[$network];
            if ($hosts !== [] && ! $this->hostMatches((string) parse_url($url, PHP_URL_HOST), $hosts)) {
                throw new InvalidSiteContent('social_host', $at.'.url', ['network' => $network]);
            }
            $clean[] = [
                'id' => $this->itemId($link['id'] ?? '', $at, $ids),
                'enabled' => $this->bool($link['enabled'] ?? true, $at.'.enabled'),
                'network' => $network,
                'url' => $url,
            ];
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function background(mixed $value, string $path): array
    {
        $value = is_array($value) ? $value : [];
        $this->keys($value, self::blankBackground(), $path);
        $type = $this->choice($value['type'] ?? 'none', SiteCatalog::BACKGROUNDS, $path.'.type');
        $image = $this->media($value['image'] ?? '', 'image', $path.'.image');
        $video = $this->media($value['video'] ?? '', 'video', $path.'.video');

        return [
            'type' => $type,
            'color' => $this->choice($value['color'] ?? 'primary', SiteCatalog::BACKGROUND_COLORS, $path.'.color'),
            'gradient' => $this->choice($value['gradient'] ?? 'brand', SiteCatalog::BACKGROUND_GRADIENTS, $path.'.gradient'),
            'image' => $image,
            'video' => $video,
            'poster' => $this->media($value['poster'] ?? '', 'image', $path.'.poster'),
            'overlay' => $this->choice($value['overlay'] ?? 'soft', SiteCatalog::OVERLAYS, $path.'.overlay'),
        ];
    }

    // ── Values ──────────────────────────────────────────────────────────────

    /**
     * Plain text per language. Every supported language is KEPT (a disabled
     * language never loses its translations); empty ones are dropped; the
     * primary language is required when the field is.
     *
     * @return array<string, string>
     */
    private function localized(mixed $value, int $max, string $path, bool $required = false): array
    {
        if (! is_array($value)) {
            throw new InvalidSiteContent('presentation', $path);
        }
        $clean = [];
        foreach ($value as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $this->context->locales, true)) {
                throw new InvalidSiteContent('unknown_field', $path.'.'.$locale);
            }
            $plain = $this->plain($text, $max, $path.'.'.$locale);
            if ($plain !== '') {
                $clean[$locale] = $plain;
            }
        }
        if ($required && ($clean[$this->context->primary] ?? '') === '') {
            throw new InvalidSiteContent('primary_required', $path.'.'.$this->context->primary);
        }

        return $clean;
    }

    private function plain(mixed $value, int $max, string $path): string
    {
        if ($value !== null && ! is_string($value) && ! is_int($value)) {
            throw new InvalidSiteContent('presentation', $path);
        }
        $value = trim((string) $value);
        if ($value !== strip_tags($value) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidSiteContent('text_only', $path);
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidSiteContent('too_long', $path, ['max' => $max]);
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(mixed $value, array $allowed, string $path): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if (! in_array($value, $allowed, true)) {
            throw new InvalidSiteContent('presentation', $path);
        }

        return $value;
    }

    /**
     * @param  list<int>  $allowed
     */
    private function int(mixed $value, string $path, array $allowed): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || ! in_array($int, $allowed, true)) {
            throw new InvalidSiteContent('presentation', $path);
        }

        return $int;
    }

    private function bool(mixed $value, string $path): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new InvalidSiteContent('presentation', $path);
        }

        return $bool;
    }

    /**
     * A media uuid this center uploaded to its site library, of the kind the
     * slot takes. Another center's uuid is simply not in the library.
     */
    private function media(mixed $value, string $kind, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        if (preg_match(self::UUID, $value) !== 1 || $this->context->mediaKind($value) !== $kind) {
            throw new InvalidSiteContent('media', $path);
        }

        return $value;
    }

    /**
     * A reference to one of this center's records. Unknown means another
     * center's (or a made-up) uuid and is refused; known-but-archived is kept
     * and simply not rendered.
     */
    private function reference(string $type, string $uuid, string $path, bool $required): string
    {
        if ($uuid === '' && ! $required) {
            return '';
        }
        if (preg_match(self::UUID, $uuid) !== 1 || $this->context->reference($type, $uuid) === null) {
            throw new InvalidSiteContent('reference', $path);
        }

        return $uuid;
    }

    /**
     * @return list<string>
     */
    private function uuids(mixed $value, string $type, string $path): array
    {
        if (! is_array($value) || count($value) > SiteCatalog::MAX_SELECTED) {
            throw new InvalidSiteContent('selection_limit', $path, ['max' => SiteCatalog::MAX_SELECTED]);
        }
        $clean = [];
        foreach (array_values($value) as $index => $uuid) {
            $uuid = $this->reference($type, is_string($uuid) ? trim($uuid) : '', $path.'.'.$index, true);
            if (! in_array($uuid, $clean, true)) {
                $clean[] = $uuid;
            }
        }

        return $clean;
    }

    private function target(string $type, mixed $value, string $path, bool $required): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' && ! $required) {
            return '';
        }

        return match ($type) {
            'section' => preg_match(self::ANCHOR, ltrim($value, '#')) === 1 ? ltrim($value, '#') : throw new InvalidSiteContent('section_target', $path),
            'page' => in_array($value, SiteCatalog::PAGES, true) ? $value : throw new InvalidSiteContent('page_target', $path),
            default => $this->https($value, $path, true),
        };
    }

    /**
     * An absolute https address with a real host and no credentials. Nothing
     * else — not http, not `javascript:`, not a data URL, not `//host`.
     */
    private function https(mixed $value, string $path, bool $required): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' && ! $required) {
            return '';
        }
        $parts = parse_url($value);
        if (mb_strlen($value) > 300
            || preg_match('/\s/', $value) === 1
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host']) || ! str_contains($parts['host'], '.')
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidSiteContent('https_url', $path);
        }

        return $value;
    }

    /**
     * @param  list<string>  $hosts
     */
    private function hostMatches(string $host, array $hosts): bool
    {
        $host = mb_strtolower($host);
        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    private function phone(mixed $value, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && preg_match(self::PHONE, $value) !== 1) {
            throw new InvalidSiteContent('phone', $path);
        }

        return $value;
    }

    private function email(mixed $value, string $path): string
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value !== '' && (mb_strlen($value) > 120 || filter_var($value, FILTER_VALIDATE_EMAIL) === false)) {
            throw new InvalidSiteContent('email', $path);
        }

        return $value;
    }

    /**
     * @param  list<string>  $taken
     */
    private function itemId(mixed $value, string $path, array &$taken): string
    {
        $id = is_string($value) ? $value : '';
        if (preg_match(self::ID, $id) !== 1 || in_array($id, $taken, true)) {
            throw new InvalidSiteContent('item_id', $path);
        }
        $taken[] = $id;

        return $id;
    }

    /**
     * Refuses any key the shape does not have.
     *
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $shape
     */
    private function keys(array $value, array $shape, string $path): void
    {
        foreach (array_keys($value) as $key) {
            if (! array_key_exists((string) $key, $shape)) {
                throw new InvalidSiteContent('unknown_field', ltrim($path.'.'.$key, '.'));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $input, string $key, string $path): array
    {
        $value = $input[$key] ?? [];
        if (! is_array($value)) {
            throw new InvalidSiteContent('presentation', ltrim($path.'.'.$key, '.'));
        }

        return $value;
    }

    // ── Hydration helpers ───────────────────────────────────────────────────

    /**
     * The shape's keys, with the stored value where it has the right KIND
     * (array for array, scalar for scalar). Localized and list values are
     * taken as stored; the normalizer judges them later.
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    private static function fit(array $shape, mixed $stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $out = [];
        foreach ($shape as $key => $blank) {
            if (! array_key_exists($key, $stored)) {
                $out[$key] = $blank;

                continue;
            }
            $value = $stored[$key];
            if (is_array($blank)) {
                // A keyed sub-structure (cta, background) is fitted in turn; a
                // localized map or a list keeps its stored entries.
                $out[$key] = $blank !== [] && ! array_is_list($blank)
                    ? self::fit($blank, $value)
                    : (is_array($value) ? $value : $blank);
            } else {
                $out[$key] = is_array($value) ? $blank : $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $blank
     * @return list<array<string, mixed>>
     */
    private static function fitList(mixed $stored, array $blank): array
    {
        $out = [];
        $ids = [];
        foreach (is_array($stored) ? array_values($stored) : [] as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $fitted = self::fit($blank, $entry);
            $id = is_string($fitted['id'] ?? null) && preg_match(self::ID, $fitted['id']) === 1 && ! in_array($fitted['id'], $ids, true)
                ? $fitted['id']
                : substr(md5($index.json_encode($entry)), 0, 8);
            while (in_array($id, $ids, true)) {
                $id = substr(md5($id.$index), 0, 8);
            }
            $fitted['id'] = $id;
            $ids[] = $id;
            $out[] = $fitted;
        }

        return $out;
    }
}
