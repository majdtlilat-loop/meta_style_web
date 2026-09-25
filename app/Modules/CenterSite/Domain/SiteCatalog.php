<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

/**
 * Everything a center may choose for its public site, as closed lists.
 *
 * Owned by CODE, like the menu, permission and entitlement catalogs: a value a
 * Blade template branches on must exist, and "highly customisable" must never
 * become "arbitrary". There is no field anywhere that accepts markup, CSS or a
 * script (ADR-038).
 *
 * Section types are only those backed by real data or by the owner's own
 * words. There is no Offers type — no offers module exists, and a placeholder
 * section would promise customers something the center cannot give them.
 */
final class SiteCatalog
{
    public const MAX_SECTIONS = 30;

    public const MAX_NAVIGATION = 8;

    public const MAX_SOCIAL = 9;

    public const MAX_FOOTER_LINKS = 8;

    public const MAX_LEGAL_LINKS = 6;

    /**
     * Section type → what it offers.
     *
     *   icon     the editor's icon for the type
     *   layouts  allowed layout variants; the first is the default
     *   items    the kind of repeatable item it holds, or null
     *   max      how many items
     *   media    whether it carries its own image / video
     *   cta      whether it carries a call to action
     *   source   the real data it renders, or null for owner-written content
     */
    public const TYPES = [
        'about' => ['icon' => 'info', 'layouts' => ['split', 'centered'], 'items' => 'feature', 'max' => 6, 'media' => true, 'cta' => true, 'source' => null],
        'services' => ['icon' => 'catalog', 'layouts' => ['grid', 'list', 'compact'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => true, 'source' => 'services'],
        'featured_services' => ['icon' => 'star', 'layouts' => ['grid', 'list', 'compact'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => true, 'source' => 'featured_services'],
        'categories' => ['icon' => 'layers', 'layouts' => ['grid', 'chips'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => true, 'source' => 'categories'],
        'team' => ['icon' => 'users', 'layouts' => ['grid', 'list'], 'items' => 'team', 'max' => 24, 'media' => false, 'cta' => true, 'source' => null],
        'why_us' => ['icon' => 'check-circle', 'layouts' => ['grid', 'list'], 'items' => 'feature', 'max' => 12, 'media' => false, 'cta' => true, 'source' => null],
        'gallery' => ['icon' => 'image', 'layouts' => ['grid', 'mosaic', 'strip'], 'items' => 'gallery', 'max' => 24, 'media' => false, 'cta' => false, 'source' => null],
        'memberships' => ['icon' => 'memberships', 'layouts' => ['grid', 'list'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => true, 'source' => 'memberships'],
        'packages' => ['icon' => 'packages', 'layouts' => ['grid', 'list'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => true, 'source' => 'packages'],
        'rating' => ['icon' => 'reviews', 'layouts' => ['centered', 'split'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => true, 'source' => 'rating'],
        'hours' => ['icon' => 'clock', 'layouts' => ['cards', 'table'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => false, 'source' => 'hours'],
        'branches' => ['icon' => 'branches', 'layouts' => ['cards', 'list'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => false, 'source' => 'branches'],
        'booking_cta' => ['icon' => 'calendar', 'layouts' => ['banner', 'centered'], 'items' => null, 'max' => 0, 'media' => true, 'cta' => true, 'source' => null],
        'contact' => ['icon' => 'phone', 'layouts' => ['cards', 'split'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => false, 'source' => 'contact'],
        'map' => ['icon' => 'map-pin', 'layouts' => ['wide', 'split'], 'items' => null, 'max' => 0, 'media' => false, 'cta' => false, 'source' => 'map'],
        'faq' => ['icon' => 'faq', 'layouts' => ['accordion', 'columns'], 'items' => 'faq', 'max' => 20, 'media' => false, 'cta' => true, 'source' => null],
        'text_media' => ['icon' => 'layout', 'layouts' => ['split', 'stacked', 'centered'], 'items' => null, 'max' => 0, 'media' => true, 'cta' => true, 'source' => null],
    ];

    /** Type-specific data options and their defaults. Unknown keys never survive. */
    public const SOURCE_DEFAULTS = [
        'services' => ['mode' => 'all', 'category_uuids' => [], 'limit' => 6, 'show_prices' => true, 'show_duration' => true, 'show_images' => true, 'link' => 'booking'],
        'featured_services' => ['service_uuids' => [], 'show_prices' => true, 'show_duration' => true, 'show_images' => true, 'link' => 'booking'],
        'categories' => ['category_uuids' => [], 'limit' => 8, 'show_images' => true],
        'memberships' => ['mode' => 'all', 'uuids' => [], 'show_prices' => true],
        'packages' => ['mode' => 'all', 'uuids' => [], 'show_prices' => true],
        'rating' => ['show_count' => true, 'show_distribution' => false],
        'hours' => ['branch_uuids' => []],
        'branches' => ['branch_uuids' => [], 'show_hours' => true, 'show_contact' => true, 'show_map_link' => true],
        'contact' => ['branch_uuid' => '', 'show_phone' => true, 'show_whatsapp' => true, 'show_email' => true, 'show_address' => true, 'show_map_link' => true],
        'map' => ['branch_uuid' => '', 'mode' => 'link', 'zoom' => 15],
    ];

    public const SERVICE_MODES = ['all', 'categories'];

    public const SELECTION_MODES = ['all', 'selected'];

    public const SERVICE_LINKS = ['booking', 'list', 'none'];

    public const MAP_MODES = ['link', 'embed'];

    public const MAX_SELECTED = 24;

    /** The icon allow-list for section and item icons (names in the UI icon set). */
    public const ICONS = [
        'sparkles', 'star', 'check-circle', 'shield', 'clock', 'calendar', 'users', 'user', 'gift', 'scissors', 'palette', 'tag',
        'zap', 'layers', 'map-pin', 'phone', 'message', 'globe', 'wallet', 'credit-card', 'percent', 'home', 'reviews', 'bell',
    ];

    /** The icon a social network is drawn with on the public page. */
    public const SOCIAL_ICONS = [
        'instagram' => 'instagram', 'facebook' => 'facebook', 'tiktok' => 'tiktok', 'x' => 'x-brand', 'youtube' => 'youtube',
        'snapchat' => 'message', 'linkedin' => 'linkedin', 'whatsapp' => 'message', 'telegram' => 'send', 'website' => 'globe',
    ];

    public const LAYOUT_COLUMNS = [2, 3, 4];

    public const ALIGNMENTS = ['start', 'center'];

    public const VISIBILITY = ['all', 'desktop', 'mobile'];

    public const MEDIA_POSITIONS = ['start', 'end'];

    public const BACKGROUNDS = ['none', 'soft', 'color', 'gradient', 'image', 'video'];

    /** Solid backgrounds come from the BRAND palette, never from a typed colour. */
    public const BACKGROUND_COLORS = ['primary', 'secondary', 'accent', 'surface', 'dark'];

    /** Named brand gradients (CenterBrand). */
    public const BACKGROUND_GRADIENTS = ['brand', 'hero', 'accent'];

    public const OVERLAYS = ['none', 'soft', 'strong'];

    public const HERO_LAYOUTS = ['split', 'centered', 'cover', 'minimal'];

    public const HERO_HEIGHTS = ['auto', 'tall', 'screen'];

    public const HEADER_STYLES = ['solid', 'transparent', 'blur'];

    public const HEADER_LAYOUTS = ['inline', 'centered'];

    public const LOGO_VARIANTS = ['auto', 'light', 'dark', 'none'];

    public const CTA_STYLES = ['primary', 'secondary', 'outline', 'link'];

    /** Where a link may go. Nothing else is a destination. */
    public const LINK_TYPES = ['section', 'page', 'external'];

    /** The center's own pages a link may name, by key — never a typed path. */
    public const PAGES = ['home', 'list', 'booking'];

    public const CANONICAL = ['self', 'none'];

    /**
     * Social networks and the hosts a link for each must live on. `website`
     * takes any https address; every other network is pinned to its own
     * domains so a "Instagram" icon can never open somewhere else.
     */
    public const SOCIAL_NETWORKS = [
        'instagram' => ['instagram.com'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.me'],
        'tiktok' => ['tiktok.com'],
        'x' => ['x.com', 'twitter.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'snapchat' => ['snapchat.com'],
        'linkedin' => ['linkedin.com'],
        'whatsapp' => ['wa.me', 'whatsapp.com'],
        'telegram' => ['t.me', 'telegram.me'],
        'website' => [],
    ];

    /**
     * Text limits, in characters.
     */
    public const LIMITS = [
        'eyebrow' => 60, 'title' => 140, 'subtitle' => 200, 'body' => 1200, 'hero_body' => 400,
        'item_title' => 120, 'item_body' => 600, 'label' => 40, 'alt' => 160,
        'seo_title' => 70, 'seo_description' => 170, 'og_title' => 95, 'og_description' => 220,
        'footer_description' => 400, 'address' => 200, 'copyright' => 160,
    ];

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    public static function isType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    /**
     * @return array{icon: string, layouts: list<string>, items: string|null, max: int, media: bool, cta: bool, source: string|null}
     */
    public static function type(string $type): array
    {
        return self::TYPES[$type] ?? self::TYPES['text_media'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sourceDefaults(string $type): array
    {
        $source = self::type($type)['source'];

        return $source === null ? [] : self::SOURCE_DEFAULTS[$source];
    }
}
