<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\CenterSite\Domain\CenterTheme;
use App\Modules\CenterSite\Domain\SiteCatalog;

/**
 * The center's public page as ONE allow-listed array, ready for Blade.
 *
 * Everything a template prints is resolved here: text in the requested
 * language with fallbacks, hrefs from link keys, media URLs from uuids, live
 * data through module contracts. The templates hold no models, no queries and
 * no decisions — only this array (CustomerPrivacyTest scans them).
 *
 * The same builder renders the PUBLISHED page for customers and the DRAFT for
 * the owner's preview; only the source content and a few flags differ.
 */
final class PublicSitePage
{
    public function __construct(
        private readonly SitePublisher $publisher,
        private readonly BrandSettings $brands,
        private readonly SiteMedia $media,
        private readonly PublicSiteSections $sections,
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
        private readonly TenantContext $tenants,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function live(string $locale): array
    {
        return $this->build($this->publisher->liveContent(), $locale, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $locale): array
    {
        return $this->build($this->publisher->editableContent(), $locale, true);
    }

    /**
     * Just the frame (brand, header, footer) for the center's other public
     * pages that share the layout.
     *
     * @return array<string, mixed>
     */
    public function shell(string $locale): array
    {
        $content = $this->publisher->liveContent();
        $content['hero']['enabled'] = false;
        $page = $this->build($content, $locale, false, false);
        $page['sections'] = [];

        return $page;
    }

    /**
     * @param  array<string, mixed>  $content  hydrated content
     * @return array<string, mixed>
     */
    private function build(array $content, string $locale, bool $preview, bool $withSections = true): array
    {
        $primary = $this->locales->default();
        $brand = $this->brands->get();
        $media = $this->media->resolve(SiteMedia::referencedBy($content));
        $assets = $this->media->resolve([$brand['logo_light'], $brand['logo_dark'], $brand['favicon']], MediaOwner::Brand);
        $name = (string) ($this->tenants->tenant()->name ?? '');

        $ctx = new SiteRenderContext($locale, $primary, $preview, $media, ['booking' => $this->entitlements->enabled('booking')]);

        // Sections first: menu links may only point at anchors that render.
        if ($withSections) {
            $sections = $this->sections($content, $ctx);
            $ctx->anchors = array_values(array_filter(array_map(static fn (array $section): string => $section['anchor'], $sections)));
        } else {
            // Another page sharing the frame: a menu link to a section goes to
            // that section on the home page.
            $sections = [];
            $ctx->anchorBase = $ctx->homeUrl();
            $ctx->anchors = $this->enabledAnchors($content);
        }

        $logoLight = $assets[$brand['logo_light']]['url'] ?? null;
        $logoDark = $assets[$brand['logo_dark']]['url'] ?? null;
        $favicon = $assets[$brand['favicon']] ?? null;

        return [
            'locale' => $locale,
            'direction' => $this->languages->direction($locale),
            'preview' => $preview,
            'theme' => [
                'css' => CenterTheme::css($brand),
                'scheme' => $brand['scheme'],
                'radius' => $brand['radius'],
                'button' => $brand['button_style'],
                'card' => $brand['card_style'],
            ],
            'brand' => [
                'name' => $name,
                'logo' => $logoLight ?? $logoDark,
                'logo_dark' => $logoDark,
                'favicon' => $favicon['url'] ?? null,
                'favicon_type' => $favicon !== null && str_ends_with($favicon['url'], '.ico') ? 'image/x-icon' : 'image/png',
            ],
            'header' => $this->header($content, $ctx, $logoLight, $logoDark, $brand['scheme']),
            'hero' => $withSections ? $this->hero($content['hero'], $ctx) : null,
            'sections' => $sections,
            'footer' => $this->footer($content['footer'], $ctx, $logoLight, $logoDark, $name),
            'seo' => $this->seo($content, $ctx, $name),
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    private function sections(array $content, SiteRenderContext $ctx): array
    {
        $out = [];
        $pending = [];
        foreach ($content['section_order'] as $id) {
            $section = $content['sections'][$id] ?? null;
            if (! is_array($section) || ! ($section['enabled'] ?? true)) {
                continue;
            }
            $pending[$id] = $section;
        }
        // Anchors first, so CTAs inside sections can point at later sections.
        $ctx->anchors = $this->enabledAnchors($content);

        foreach ($pending as $id => $section) {
            $data = $this->sections->data($section, $ctx);
            $view = $this->section((string) $id, $section, $ctx, $data);
            if ($view !== null) {
                $out[] = $view;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    private function section(string $id, array $section, SiteRenderContext $ctx, ?array $data): ?array
    {
        $type = (string) $section['type'];
        $spec = SiteCatalog::type($type);
        $title = $ctx->text($section['title']);
        $body = $ctx->text($section['body']);
        $image = $spec['media'] ? $ctx->image($section['image'], $section['image_alt'], $title) : null;
        $video = $spec['media'] ? $ctx->video($section['video'], $section['poster']) : null;
        $cta = $spec['cta'] ? $ctx->cta($section['cta']) : null;

        // Owner-written sections with nothing written, and data sections with
        // no data, do not render. The preview keeps them, marked, so the owner
        // can see why the live page will not show them.
        $empty = $data === null
            || ($spec['source'] === null && ! in_array($type, ['team', 'gallery', 'faq'], true)
                && $title === '' && $body === '' && ($data['items'] ?? []) === [] && $image === null && $video === null && $cta === null);
        if ($empty && ! $ctx->preview) {
            return null;
        }

        return [
            'id' => $id,
            'type' => $type,
            'template' => match ($type) {
                'featured_services' => 'services',
                'memberships', 'packages' => 'offers',
                default => $type,
            },
            'anchor' => (string) $section['anchor'],
            'visibility' => (string) $section['visibility'],
            'layout' => (string) $section['layout'],
            'columns' => (int) $section['columns'],
            'alignment' => (string) $section['alignment'],
            'icon' => (string) $section['icon'],
            'eyebrow' => $ctx->text($section['eyebrow']),
            'title' => $title,
            'subtitle' => $ctx->text($section['subtitle']),
            'body' => $body,
            'media' => ['image' => $image, 'video' => $video, 'position' => (string) $section['media_position']],
            'cta' => $cta,
            'background' => $this->background($section['background'], $ctx),
            'data' => $data ?? [],
            'empty' => $empty,
        ];
    }

    /**
     * @param  array<string, mixed>  $hero
     * @return array<string, mixed>|null
     */
    private function hero(array $hero, SiteRenderContext $ctx): ?array
    {
        $title = $ctx->text($hero['title']);
        if (! ($hero['enabled'] ?? true) || $title === '') {
            return null;
        }

        $image = $ctx->image($hero['image'], $hero['image_alt'], $title);
        $video = $ctx->video($hero['video'], $hero['poster']);
        $background = $this->background($hero['background'], $ctx);
        $cover = $hero['layout'] === 'cover' && ($image !== null || $video !== null);

        return [
            'layout' => (string) $hero['layout'],
            'alignment' => (string) $hero['alignment'],
            'height' => (string) $hero['height'],
            'eyebrow' => $ctx->text($hero['eyebrow']),
            'title' => $title,
            'subtitle' => $ctx->text($hero['subtitle']),
            'body' => $ctx->text($hero['body']),
            'ctas' => array_values(array_filter([$ctx->cta($hero['primary_cta']), $ctx->cta($hero['secondary_cta'])])),
            'image' => $image,
            'video' => $video,
            'cover' => $cover,
            // A cover hero draws its own media as the backdrop.
            'background' => $cover ? ['type' => 'none'] + $background : $background,
            'inverse' => $cover || $background['inverse'],
        ];
    }

    /**
     * @param  array<string, mixed>  $background
     * @return array<string, mixed>
     */
    private function background(array $background, SiteRenderContext $ctx): array
    {
        $type = (string) ($background['type'] ?? 'none');
        $image = $type === 'image' ? $ctx->image($background['image'] ?? '') : null;
        $video = $type === 'video' ? $ctx->video($background['video'] ?? '', $background['poster'] ?? '') : null;
        if (($type === 'image' && $image === null) || ($type === 'video' && $video === null)) {
            $type = 'none';
        }
        $color = (string) ($background['color'] ?? 'primary');
        $gradient = (string) ($background['gradient'] ?? 'brand');

        // Light text on a dark or coloured backdrop; the stylesheet picks the
        // on-colour token for solid brand colours.
        $inverse = in_array($type, ['image', 'video'], true)
            || ($type === 'color' && in_array($color, ['primary', 'secondary', 'dark'], true))
            || ($type === 'gradient' && in_array($gradient, ['brand', 'hero'], true));

        return [
            'type' => $type,
            'color' => $color,
            'gradient' => $gradient,
            'image' => $image['url'] ?? null,
            'video' => $video,
            'overlay' => (string) ($background['overlay'] ?? 'soft'),
            'inverse' => $inverse,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function header(array $content, SiteRenderContext $ctx, ?string $logoLight, ?string $logoDark, string $scheme): array
    {
        $header = $content['header'];
        $variant = (string) $header['logo'];
        // `auto` follows the site's scheme: the dark logo on an always-dark
        // site, and on an `auto` site whenever the visitor's device is dark.
        $logo = match (true) {
            $variant === 'none' => null,
            $variant === 'dark', $variant === 'auto' && $scheme === 'dark' => $logoDark ?? $logoLight,
            default => $logoLight ?? $logoDark,
        };
        $languages = [];
        if (($header['show_language_switch'] ?? true) && count($this->locales->enabled()) > 1) {
            foreach ($this->locales->enabled() as $code) {
                $languages[] = [
                    'code' => $code,
                    'short' => $this->languages->shortLabel($code),
                    'native' => $this->languages->nativeName($code),
                    'active' => $code === $ctx->locale,
                    'href' => $ctx->preview
                        ? route('center.appearance.site.preview', ['lang' => $code])
                        : route('center.public', ['locale' => $code]),
                ];
            }
        }

        return [
            'style' => (string) $header['style'],
            'layout' => (string) $header['layout'],
            'sticky' => (bool) $header['sticky'],
            'show_name' => (bool) $header['show_name'] || $logo === null,
            'logo' => $logo,
            'logo_dark' => $variant === 'auto' && $scheme === 'auto' && $logoDark !== null && $logoDark !== $logo ? $logoDark : null,
            'nav' => $ctx->links((array) $content['navigation']),
            'cta' => $ctx->cta($header['cta']),
            'languages' => $languages,
            'home' => $ctx->homeUrl(),
        ];
    }

    /**
     * @param  array<string, mixed>  $footer
     * @return array<string, mixed>|null
     */
    private function footer(array $footer, SiteRenderContext $ctx, ?string $logoLight, ?string $logoDark, string $name): ?array
    {
        if (! ($footer['enabled'] ?? true)) {
            return null;
        }
        $social = [];
        foreach ((array) $footer['social'] as $link) {
            if (is_array($link) && ($link['enabled'] ?? true) && str_starts_with((string) ($link['url'] ?? ''), 'https://')) {
                $network = (string) $link['network'];
                $social[] = ['network' => $network, 'icon' => SiteCatalog::SOCIAL_ICONS[$network] ?? 'globe', 'url' => (string) $link['url'], 'label' => (string) __('center_site.social.'.$network)];
            }
        }
        $copyright = $ctx->text($footer['copyright']);

        return [
            'show_logo' => (bool) $footer['show_logo'],
            'logo' => $logoLight ?? $logoDark,
            'logo_dark' => $logoDark,
            'name' => $name,
            'description' => $ctx->text($footer['description']),
            'address' => $ctx->text($footer['address']),
            'phone' => $ctx->contact('phone', (string) $footer['contact_phone']),
            'whatsapp' => $ctx->contact('whatsapp', (string) $footer['contact_whatsapp']),
            'email' => $ctx->contact('email', (string) $footer['contact_email']),
            'hours' => ($footer['show_hours'] ?? true) ? $this->sections->mainHours($ctx) : [],
            'social' => $social,
            'navigation' => $ctx->links((array) $footer['navigation']),
            'legal' => $ctx->links((array) $footer['legal']),
            'copyright' => '© '.date('Y').' '.($copyright !== '' ? $copyright : $name),
            'cta' => ($footer['show_booking_cta'] ?? true) && $ctx->features['booking']
                ? ['label' => (string) __('center_site.book_now'), 'href' => (string) $ctx->href('page', 'booking'), 'style' => 'primary', 'new_tab' => false]
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function seo(array $content, SiteRenderContext $ctx, string $name): array
    {
        $seo = $content['seo'];
        $title = $ctx->text($seo['title']);
        $title = $title !== '' ? $title : $name;
        $description = $ctx->text($seo['description']);
        $image = $ctx->image($seo['og_image'], $seo['og_image_alt'], $title) ?? $ctx->image($content['hero']['image'] ?? '', $content['hero']['image_alt'] ?? [], $title);
        $self = route('center.public', $ctx->locale === $ctx->primary ? [] : ['locale' => $ctx->locale]);

        $alternates = [];
        if (! $ctx->preview && count($this->locales->enabled()) > 1) {
            foreach ($this->locales->enabled() as $code) {
                $alternates[] = ['hreflang' => $code, 'href' => route('center.public', $code === $ctx->primary ? [] : ['locale' => $code])];
            }
            $alternates[] = ['hreflang' => 'x-default', 'href' => route('center.public')];
        }

        return [
            'title' => $title,
            'description' => $description,
            'robots' => $ctx->preview ? 'noindex, nofollow' : (($seo['index'] ? 'index' : 'noindex').', '.($seo['follow'] ? 'follow' : 'nofollow')),
            'canonical' => ! $ctx->preview && $seo['canonical'] === 'self' ? $self : null,
            'og' => [
                'title' => $ctx->text($seo['og_title']) ?: $title,
                'description' => $ctx->text($seo['og_description']) ?: $description,
                'image' => $image['url'] ?? null,
                'image_alt' => $image['alt'] ?? $title,
                'url' => $self,
                'locale' => str_replace('-', '_', $ctx->locale),
                'site_name' => $name,
            ],
            'alternates' => $alternates,
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    private function enabledAnchors(array $content): array
    {
        $anchors = [];
        foreach ($content['section_order'] as $id) {
            $section = $content['sections'][$id] ?? null;
            if (is_array($section) && ($section['enabled'] ?? true) && ($section['anchor'] ?? '') !== '') {
                $anchors[] = (string) $section['anchor'];
            }
        }

        return $anchors;
    }
}
