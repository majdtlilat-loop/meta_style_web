<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Branches\Contracts\SiteBranchReader;
use App\Modules\CenterSite\Application\SiteAccess;
use App\Modules\CenterSite\Application\SiteMedia;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\CenterSite\Application\SiteReferences;
use App\Modules\CenterSite\Domain\Models\SiteVersion;
use App\Modules\CenterSite\Domain\SiteCatalog;
use App\Modules\CenterSite\Domain\SiteContent;
use App\Modules\CenterSite\Domain\SiteEditor;
use Carbon\CarbonInterface;

/**
 * Everything the site builder's Blade needs, computed in PHP: the outline,
 * the current panel's data, labels for every choice, resolved media previews,
 * picker options, and the draft/published state. The templates only print.
 */
final class SiteEditorView
{
    public function __construct(
        private readonly SitePublisher $publisher,
        private readonly SiteReferences $references,
        private readonly SiteMedia $media,
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
        private readonly SiteBranchReader $branches,
    ) {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public function data(array $content, string $panel, User $user): array
    {
        $ui = app()->getLocale();
        $primary = $this->locales->default();
        $current = str_starts_with($panel, 'section:') ? mb_substr($panel, 8) : null;
        $section = $current !== null && is_array($content['sections'][$current] ?? null) ? $content['sections'][$current] : null;
        $spec = $section !== null ? SiteCatalog::type((string) $section['type']) : null;
        $needsOptions = $spec !== null && ($spec['source'] !== null || $spec['items'] === 'team');
        $options = $needsOptions ? $this->references->options($ui) : null;

        return [
            'canManage' => SiteAccess::canManage($user),
            'locales' => $this->locales->enabled(),
            'primary' => $primary,
            'outline' => $this->outline($content, $primary, $ui),
            'current' => $section !== null ? $current : null,
            'section' => $section,
            'spec' => $spec,
            'anchors' => SiteEditor::anchors($content),
            'media' => $this->media->resolve(SiteMedia::referencedBy($content)),
            'options' => $options,
            'names' => $this->names($options),
            'state' => $this->state(),
            'choices' => $this->choices(),
            'previewLocales' => array_map(fn (string $code): array => ['code' => $code, 'short' => $this->languages->shortLabel($code)], $this->locales->enabled()),
            'previewUrl' => route('center.appearance.site.preview'),
            'publicUrl' => route('center.public'),
            'brandUrl' => route('center.appearance.brand'),
        ];
    }

    /**
     * Stored content as the editor holds it.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function fresh(array $content): array
    {
        return SiteContent::hydrate($content);
    }

    /**
     * Whether `$target` names an image or video slot that exists in this
     * draft. Anything else is ignored: an upload can only land in a real slot.
     *
     * @param  array<string, mixed>  $content
     */
    public static function isMediaTarget(array $content, string $target): bool
    {
        if (in_array($target, ['hero.image', 'hero.video', 'hero.poster', 'hero.background.image', 'hero.background.video', 'hero.background.poster', 'seo.og_image'], true)) {
            return true;
        }
        if (preg_match('/^sections\.([a-z][a-z0-9_]{1,40})\.(image|video|poster|background\.image|background\.video|background\.poster)$/', $target, $m) === 1) {
            return isset($content['sections'][$m[1]]);
        }
        if (preg_match('/^sections\.([a-z][a-z0-9_]{1,40})\.items\.(\d{1,2})\.image$/', $target, $m) === 1) {
            return isset($content['sections'][$m[1]]['items'][(int) $m[2]]);
        }

        return false;
    }

    /**
     * The panel that holds the field a refusal names.
     *
     * @param  array<string, mixed>  $content
     */
    public static function panelFor(array $content, string $path): ?string
    {
        $root = explode('.', $path)[0];
        if ($root === 'sections') {
            $id = explode('.', $path)[1] ?? '';

            return isset($content['sections'][$id]) ? 'section:'.$id : null;
        }

        return in_array($root, ['header', 'navigation', 'hero', 'footer', 'seo'], true) ? $root : null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<array{id: string, type: string, label: string, type_label: string, icon: string, enabled: bool, anchor: string}>
     */
    private function outline(array $content, string $primary, string $ui): array
    {
        $outline = [];
        foreach ((array) $content['section_order'] as $id) {
            $section = $content['sections'][$id] ?? null;
            if (! is_array($section)) {
                continue;
            }
            $type = (string) $section['type'];
            $title = trim((string) ($section['title'][$ui] ?? $section['title'][$primary] ?? ''));
            $typeLabel = (string) __('manager_site.types.'.$type.'.label');
            $outline[] = [
                'id' => (string) $id,
                'type' => $type,
                'label' => $title !== '' ? $title : $typeLabel,
                'type_label' => $typeLabel,
                'icon' => SiteCatalog::type($type)['icon'],
                'enabled' => (bool) ($section['enabled'] ?? true),
                'anchor' => (string) ($section['anchor'] ?? ''),
            ];
        }

        return $outline;
    }

    /**
     * uuid => name for every picker list, so a template can label a stored
     * reference without searching.
     *
     * @param  array<string, mixed>|null  $options
     * @return array<string, array<string, string>>
     */
    private function names(?array $options): array
    {
        $names = [];
        foreach (['services', 'categories', 'employees', 'branches', 'memberships', 'packages'] as $list) {
            $names[$list] = [];
            foreach ((array) ($options[$list] ?? []) as $row) {
                if (is_array($row) && isset($row['uuid'], $row['name'])) {
                    $names[$list][(string) $row['uuid']] = (string) $row['name'];
                }
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $published = $this->publisher->published();
        $draft = $this->publisher->currentDraft();
        $timezone = $this->branches->mainTimezone();
        $when = static fn (?CarbonInterface $at): ?string => $at?->copy()->setTimezone($timezone)->translatedFormat('j M Y, H:i');

        return [
            'published' => $published instanceof SiteVersion ? [
                'version' => $published->version,
                'at' => $when($published->published_at),
                'by' => $published->publishedBy?->name,
            ] : null,
            'draft' => $draft instanceof SiteVersion ? [
                'version' => $draft->version,
                'at' => $when($draft->updated_at),
                'restored_from' => $draft->restored_from_version,
            ] : null,
            'pending' => $draft instanceof SiteVersion
                && (! $published instanceof SiteVersion || SiteContent::hydrate($draft->content) !== SiteContent::hydrate($published->content)),
        ];
    }

    /**
     * Every choice the panels offer, with its translated label.
     *
     * @return array<string, mixed>
     */
    private function choices(): array
    {
        $label = static fn (string $group, array $values): array => array_combine($values, array_map(static fn (string $value): string => (string) __('manager_site.options.'.$group.'.'.$value), $values));

        $types = [];
        foreach (SiteCatalog::types() as $type) {
            $types[$type] = [
                'icon' => SiteCatalog::type($type)['icon'],
                'label' => (string) __('manager_site.types.'.$type.'.label'),
                'help' => (string) __('manager_site.types.'.$type.'.help'),
            ];
        }
        $layouts = [];
        foreach (SiteCatalog::TYPES as $type => $spec) {
            $layouts[$type] = $label('layout', $spec['layouts']);
        }

        return [
            'types' => $types,
            'layouts' => $layouts,
            'hero_layouts' => $label('hero_layout', SiteCatalog::HERO_LAYOUTS),
            'hero_heights' => $label('hero_height', SiteCatalog::HERO_HEIGHTS),
            'alignments' => $label('alignment', SiteCatalog::ALIGNMENTS),
            'visibility' => $label('visibility', SiteCatalog::VISIBILITY),
            'media_positions' => $label('media_position', SiteCatalog::MEDIA_POSITIONS),
            'backgrounds' => $label('background', SiteCatalog::BACKGROUNDS),
            'background_colors' => $label('background_color', SiteCatalog::BACKGROUND_COLORS),
            'gradients' => $label('gradient', SiteCatalog::BACKGROUND_GRADIENTS),
            'overlays' => $label('overlay', SiteCatalog::OVERLAYS),
            'header_styles' => $label('header_style', SiteCatalog::HEADER_STYLES),
            'header_layouts' => $label('header_layout', SiteCatalog::HEADER_LAYOUTS),
            'logos' => $label('logo', SiteCatalog::LOGO_VARIANTS),
            'cta_styles' => $label('cta_style', SiteCatalog::CTA_STYLES),
            'link_types' => $label('link_type', SiteCatalog::LINK_TYPES),
            'pages' => $label('page', SiteCatalog::PAGES),
            'canonical' => $label('canonical', SiteCatalog::CANONICAL),
            'service_modes' => $label('service_mode', SiteCatalog::SERVICE_MODES),
            'selection_modes' => $label('selection_mode', SiteCatalog::SELECTION_MODES),
            'service_links' => $label('service_link', SiteCatalog::SERVICE_LINKS),
            'map_modes' => $label('map_mode', SiteCatalog::MAP_MODES),
            'networks' => $label('network', array_keys(SiteCatalog::SOCIAL_NETWORKS)),
            'icons' => SiteCatalog::ICONS,
            'columns' => SiteCatalog::LAYOUT_COLUMNS,
            'limits' => SiteCatalog::LIMITS,
            'rating_min' => max(1, (int) config('site.rating.min_reviews', 3)),
            'max' => ['navigation' => SiteCatalog::MAX_NAVIGATION, 'social' => SiteCatalog::MAX_SOCIAL, 'footer' => SiteCatalog::MAX_FOOTER_LINKS, 'legal' => SiteCatalog::MAX_LEGAL_LINKS, 'selected' => SiteCatalog::MAX_SELECTED],
        ];
    }
}
