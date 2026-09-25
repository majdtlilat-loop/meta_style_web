<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain;

/**
 * The builder's structural operations, as pure functions over the content
 * array: add, duplicate, remove, toggle and reorder sections, and the same for
 * the items of every repeatable list.
 *
 * Kept out of the Livewire component so the component stays orchestration and
 * so every operation is testable without a browser. Nothing here trusts a
 * client-sent order: a move names ONE item and a target position, and the
 * position is clamped to the list that actually exists. Unknown ids and list
 * paths are no-ops. The result is still only a draft — SiteContent validates
 * everything on save.
 */
final class SiteEditor
{
    /** The repeatable lists an operation may address, besides section items. */
    private const LISTS = [
        'navigation' => ['kind' => 'link', 'max' => SiteCatalog::MAX_NAVIGATION],
        'footer.navigation' => ['kind' => 'link', 'max' => SiteCatalog::MAX_FOOTER_LINKS],
        'footer.legal' => ['kind' => 'link', 'max' => SiteCatalog::MAX_LEGAL_LINKS],
        'footer.social' => ['kind' => 'social', 'max' => SiteCatalog::MAX_SOCIAL],
    ];

    // ── Sections ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $content
     * @return array{0: array<string, mixed>, 1: string|null} the content and the new section id
     */
    public static function addSection(array $content, string $type, ?string $after = null): array
    {
        if (! SiteCatalog::isType($type) || count($content['sections'] ?? []) >= SiteCatalog::MAX_SECTIONS) {
            return [$content, null];
        }
        $id = self::uniqueSectionId($content, $type);
        $section = SiteContent::blankSection($type);
        $section['anchor'] = self::uniqueAnchor($content, str_replace('_', '-', $type));
        $content['sections'][$id] = $section;

        return [self::insertAfter($content, $id, $after), $id];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    public static function duplicateSection(array $content, string $id): array
    {
        $source = $content['sections'][$id] ?? null;
        if (! is_array($source) || count($content['sections']) >= SiteCatalog::MAX_SECTIONS) {
            return [$content, null];
        }
        $copy = $source;
        $copy['anchor'] = ($source['anchor'] ?? '') === '' ? '' : self::uniqueAnchor($content, (string) $source['anchor']);
        $copy['items'] = array_map(static fn (array $item): array => ['id' => SiteContent::newId()] + $item, (array) ($source['items'] ?? []));
        $newId = self::uniqueSectionId($content, (string) ($source['type'] ?? 'section'));
        $content['sections'][$newId] = $copy;

        return [self::insertAfter($content, $newId, $id), $newId];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function removeSection(array $content, string $id): array
    {
        if (! isset($content['sections'][$id])) {
            return $content;
        }
        unset($content['sections'][$id]);
        $content['section_order'] = array_values(array_filter(
            (array) ($content['section_order'] ?? []),
            static fn (mixed $value): bool => $value !== $id,
        ));

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function toggleSection(array $content, string $id): array
    {
        if (isset($content['sections'][$id])) {
            $content['sections'][$id]['enabled'] = ! (bool) ($content['sections'][$id]['enabled'] ?? true);
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function moveSection(array $content, string $id, int $direction): array
    {
        $order = array_values((array) ($content['section_order'] ?? []));
        $index = array_search($id, $order, true);
        if ($index === false) {
            return $content;
        }

        return self::placeSection($content, $id, (int) $index + ($direction < 0 ? -1 : 1));
    }

    /**
     * Puts one section at a position (drag and drop, keyboard). The position is
     * clamped to the real list.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function placeSection(array $content, string $id, int $index): array
    {
        $order = array_values((array) ($content['section_order'] ?? []));
        $from = array_search($id, $order, true);
        if ($from === false) {
            return $content;
        }
        $content['section_order'] = self::place($order, (int) $from, $index);

        return $content;
    }

    // ── Repeatable lists ────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $content
     * @return array{0: array<string, mixed>, 1: string|null} the content and the new item id
     */
    public static function addItem(array $content, string $list): array
    {
        $spec = self::listSpec($content, $list);
        if ($spec === null) {
            return [$content, null];
        }
        $items = array_values((array) data_get($content, $list, []));
        if (count($items) >= $spec['max']) {
            return [$content, null];
        }
        $item = match ($spec['kind']) {
            'link' => SiteContent::blankLink(),
            'social' => SiteContent::blankSocial(),
            default => SiteContent::blankItem($spec['kind']),
        };
        if ($spec['kind'] === 'link') {
            $item['target'] = self::firstAnchor($content);
            $item['link_type'] = $item['target'] === '' ? 'page' : 'section';
            $item['target'] = $item['target'] === '' ? 'booking' : $item['target'];
        }
        $items[] = $item;
        data_set($content, $list, $items);

        return [$content, (string) $item['id']];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function duplicateItem(array $content, string $list, string $itemId): array
    {
        $spec = self::listSpec($content, $list);
        $items = array_values((array) data_get($content, $list, []));
        $index = self::indexOf($items, $itemId);
        if ($spec === null || $index === null || count($items) >= $spec['max']) {
            return $content;
        }
        $copy = $items[$index];
        $copy['id'] = SiteContent::newId();
        array_splice($items, $index + 1, 0, [$copy]);
        data_set($content, $list, $items);

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function removeItem(array $content, string $list, string $itemId): array
    {
        if (self::listSpec($content, $list) === null) {
            return $content;
        }
        $items = array_values((array) data_get($content, $list, []));
        $index = self::indexOf($items, $itemId);
        if ($index !== null) {
            array_splice($items, $index, 1);
            data_set($content, $list, $items);
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function moveItem(array $content, string $list, string $itemId, int $direction): array
    {
        $items = array_values((array) data_get($content, $list, []));
        $index = self::indexOf($items, $itemId);

        return $index === null ? $content : self::placeItem($content, $list, $itemId, $index + ($direction < 0 ? -1 : 1));
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function placeItem(array $content, string $list, string $itemId, int $to): array
    {
        if (self::listSpec($content, $list) === null) {
            return $content;
        }
        $items = array_values((array) data_get($content, $list, []));
        $index = self::indexOf($items, $itemId);
        if ($index !== null) {
            data_set($content, $list, self::place($items, $index, $to));
        }

        return $content;
    }

    /**
     * Whether a list path is one the builder may address, and what it holds.
     *
     * @param  array<string, mixed>  $content
     * @return array{kind: string, max: int}|null
     */
    public static function listSpec(array $content, string $list): ?array
    {
        if (isset(self::LISTS[$list])) {
            return self::LISTS[$list];
        }
        if (preg_match('/^sections\.([a-z][a-z0-9_]{1,40})\.items$/', $list, $match) === 1 && isset($content['sections'][$match[1]])) {
            $spec = SiteCatalog::type((string) ($content['sections'][$match[1]]['type'] ?? ''));

            return $spec['items'] === null ? null : ['kind' => (string) $spec['items'], 'max' => $spec['max']];
        }

        return null;
    }

    /**
     * Anchors the page actually renders, in page order — the choices a menu
     * link may point at.
     *
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function anchors(array $content): array
    {
        $anchors = [];
        foreach ((array) ($content['section_order'] ?? []) as $id) {
            $anchor = (string) ($content['sections'][$id]['anchor'] ?? '');
            if ($anchor !== '') {
                $anchors[] = $anchor;
            }
        }

        return $anchors;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private static function place(array $items, int $from, int $to): array
    {
        $to = max(0, min(count($items) - 1, $to));
        if ($from === $to) {
            return $items;
        }
        $moved = array_splice($items, $from, 1);
        array_splice($items, $to, 0, $moved);

        return $items;
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function indexOf(array $items, string $id): ?int
    {
        foreach ($items as $index => $item) {
            if (is_array($item) && ($item['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function insertAfter(array $content, string $id, ?string $after): array
    {
        $order = array_values(array_filter((array) ($content['section_order'] ?? []), static fn (mixed $value): bool => $value !== $id));
        $position = $after !== null ? array_search($after, $order, true) : false;
        if ($position === false) {
            $order[] = $id;
        } else {
            array_splice($order, (int) $position + 1, 0, [$id]);
        }
        $content['section_order'] = $order;

        return $content;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private static function uniqueSectionId(array $content, string $type): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower($type)) ?: 'section';
        $base = preg_match('/^[a-z]/', $base) === 1 ? $base : 's'.$base;
        $id = $base;
        $n = 2;
        while (isset($content['sections'][$id])) {
            $id = $base.'_'.$n++;
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private static function uniqueAnchor(array $content, string $base): string
    {
        $base = trim(preg_replace('/[^a-z0-9-]/', '', strtolower($base)) ?: 'section', '-');
        $taken = array_map(static fn (mixed $section): string => is_array($section) ? (string) ($section['anchor'] ?? '') : '', (array) ($content['sections'] ?? []));
        $anchor = $base;
        $n = 2;
        while (in_array($anchor, $taken, true)) {
            $anchor = $base.'-'.$n++;
        }

        return $anchor;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private static function firstAnchor(array $content): string
    {
        return self::anchors($content)[0] ?? '';
    }
}
