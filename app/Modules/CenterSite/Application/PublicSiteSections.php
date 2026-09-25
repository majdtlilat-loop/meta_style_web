<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Modules\Branches\Contracts\SiteBranchReader;
use App\Modules\Catalog\Contracts\SiteCatalogReader;
use App\Modules\CenterSite\Domain\SiteCatalog;
use App\Modules\Employees\Contracts\PublicTeamReader;
use App\Modules\Memberships\Contracts\SiteMembershipReader;
use App\Modules\Packages\Contracts\SitePackageReader;
use App\Modules\Reviews\Contracts\PublicRatingReader;

/**
 * The live data behind each data-backed section, as allow-listed arrays.
 *
 * Every read goes through the owning module's contract. Branches (hours,
 * contact, map) are read ONCE per render and shared; every other source is at
 * most one bounded call per section, so the page costs a fixed number of
 * queries however large the catalog is.
 *
 * A data section with nothing to show returns null and is not rendered —
 * there are no placeholders on a customer's page.
 */
final class PublicSiteSections
{
    /** Saturday first: the working week in this market. */
    private const WEEK = [6, 0, 1, 2, 3, 4, 5];

    /** @var list<array<string, mixed>>|null */
    private ?array $branches = null;

    public function __construct(
        private readonly SiteCatalogReader $catalog,
        private readonly PublicTeamReader $team,
        private readonly SiteBranchReader $branchReader,
        private readonly SiteMembershipReader $memberships,
        private readonly SitePackageReader $packages,
        private readonly PublicRatingReader $ratings,
    ) {}

    /**
     * @param  array<string, mixed>  $section  hydrated section content
     * @return array<string, mixed>|null null when there is nothing to show
     */
    public function data(array $section, SiteRenderContext $ctx): ?array
    {
        $source = is_array($section['source'] ?? null) ? $section['source'] : [];

        return match ((string) ($section['type'] ?? '')) {
            'services' => $this->services($source, $ctx, false),
            'featured_services' => $this->services($source, $ctx, true),
            'categories' => $this->categories($source, $ctx),
            'team' => $this->team($section, $ctx),
            'why_us', 'about' => $this->features($section, $ctx),
            'gallery' => $this->gallery($section, $ctx),
            'faq' => $this->faq($section, $ctx),
            'memberships' => $this->offers($source, $ctx, 'memberships'),
            'packages' => $this->offers($source, $ctx, 'packages'),
            'rating' => $this->rating($source),
            'hours' => $this->hours($source, $ctx),
            'branches' => $this->branchCards($source, $ctx),
            'contact' => $this->contact($source, $ctx),
            'map' => $this->map($source, $ctx),
            default => [],
        };
    }

    /**
     * The main public branch's week, for the footer.
     *
     * @return list<array{day: string, value: string}>
     */
    public function mainHours(SiteRenderContext $ctx): array
    {
        $main = $this->allBranches($ctx)[0] ?? null;

        return $main === null ? [] : $this->week((array) $main['hours'], $ctx);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function services(array $source, SiteRenderContext $ctx, bool $featured): ?array
    {
        $items = $featured
            ? $this->catalog->services($this->uuids($source['service_uuids'] ?? []), null, SiteCatalog::MAX_SELECTED, $ctx->locale)
            : $this->catalog->services(
                null,
                ($source['mode'] ?? 'all') === 'categories' ? $this->uuids($source['category_uuids'] ?? []) : null,
                (int) ($source['limit'] ?? 6),
                $ctx->locale,
            );
        if ($items === []) {
            return null;
        }
        $link = (string) ($source['link'] ?? 'booking');
        $href = match ($link) {
            'booking' => $ctx->href('page', 'booking'),
            'list' => $ctx->href('page', 'list'),
            default => null,
        };

        return [
            'items' => $items,
            'show_prices' => (bool) ($source['show_prices'] ?? true),
            'show_duration' => (bool) ($source['show_duration'] ?? true),
            'show_images' => (bool) ($source['show_images'] ?? true),
            'href' => $href,
            'link' => $href === null ? 'none' : $link,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function categories(array $source, SiteRenderContext $ctx): ?array
    {
        $selected = $this->uuids($source['category_uuids'] ?? []);
        $items = $this->catalog->categories($selected === [] ? null : $selected, (int) ($source['limit'] ?? 8), $ctx->locale);

        return $items === [] ? null : ['items' => $items, 'show_images' => (bool) ($source['show_images'] ?? true), 'href' => $ctx->href('page', 'list')];
    }

    /**
     * Only the members the owner chose, only while they are active.
     *
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>|null
     */
    private function team(array $section, SiteRenderContext $ctx): ?array
    {
        $items = array_values(array_filter((array) ($section['items'] ?? []), static fn (mixed $item): bool => is_array($item) && ($item['enabled'] ?? true) && ($item['employee_uuid'] ?? '') !== ''));
        $members = $this->team->members(array_map(static fn (array $item): string => (string) $item['employee_uuid'], $items), $ctx->locale);

        $out = [];
        foreach ($items as $item) {
            $member = $members[(string) $item['employee_uuid']] ?? null;
            if ($member === null) {
                continue;
            }
            $out[] = [
                'name' => $member['name'],
                'initial' => mb_strtoupper(mb_substr($member['name'], 0, 1)),
                'title' => $ctx->text($item['title'] ?? []),
                'bio' => $ctx->text($item['body'] ?? []),
                'photo' => $ctx->image($item['image'] ?? '', $item['image_alt'] ?? [], $member['name']),
            ];
        }

        return $out === [] ? null : ['items' => $out];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function features(array $section, SiteRenderContext $ctx): array
    {
        $items = [];
        foreach ((array) ($section['items'] ?? []) as $item) {
            if (! is_array($item) || ! ($item['enabled'] ?? true)) {
                continue;
            }
            $title = $ctx->text($item['title'] ?? []);
            $body = $ctx->text($item['body'] ?? []);
            if ($title === '' && $body === '') {
                continue;
            }
            $items[] = ['icon' => (string) ($item['icon'] ?? 'sparkles'), 'title' => $title, 'body' => $body, 'image' => $ctx->image($item['image'] ?? '', $item['image_alt'] ?? [], $title)];
        }

        return ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>|null
     */
    private function gallery(array $section, SiteRenderContext $ctx): ?array
    {
        $items = [];
        foreach ((array) ($section['items'] ?? []) as $item) {
            if (! is_array($item) || ! ($item['enabled'] ?? true)) {
                continue;
            }
            $caption = $ctx->text($item['title'] ?? []);
            $image = $ctx->image($item['image'] ?? '', $item['image_alt'] ?? [], $caption);
            if ($image !== null) {
                $items[] = ['url' => $image['url'], 'alt' => $image['alt'], 'caption' => $caption];
            }
        }

        return $items === [] ? null : ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>|null
     */
    private function faq(array $section, SiteRenderContext $ctx): ?array
    {
        $items = [];
        foreach ((array) ($section['items'] ?? []) as $item) {
            if (! is_array($item) || ! ($item['enabled'] ?? true)) {
                continue;
            }
            $question = $ctx->text($item['title'] ?? []);
            $answer = $ctx->text($item['body'] ?? []);
            if ($question !== '' && $answer !== '') {
                $items[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $items === [] ? null : ['items' => $items];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function offers(array $source, SiteRenderContext $ctx, string $kind): ?array
    {
        $uuids = ($source['mode'] ?? 'all') === 'selected' ? $this->uuids($source['uuids'] ?? []) : null;
        $items = $kind === 'memberships'
            ? array_map(static fn (array $plan): array => ['name' => $plan['name'], 'price' => $plan['price'], 'term' => __('center_site.offers.days', ['count' => $plan['duration_days']]), 'detail' => $plan['benefits'] > 0 ? trans_choice('center_site.offers.benefits', $plan['benefits'], ['count' => $plan['benefits']]) : ''], $this->memberships->plans($uuids, $ctx->locale))
            : array_map(static fn (array $package): array => ['name' => $package['name'], 'price' => $package['price'], 'term' => __('center_site.offers.valid_days', ['count' => $package['validity_days']]), 'detail' => $package['sessions'] > 0 ? trans_choice('center_site.offers.sessions', $package['sessions'], ['count' => $package['sessions']]) : ''], $this->packages->packages($uuids, $ctx->locale));

        return $items === [] ? null : ['items' => $items, 'show_prices' => (bool) ($source['show_prices'] ?? true)];
    }

    /**
     * The aggregate only, and only once enough reviews count toward it.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function rating(array $source): ?array
    {
        $summary = $this->ratings->summary();
        $minimum = max(1, (int) config('site.rating.min_reviews', 3));
        if ($summary['average'] === null || $summary['count'] < $minimum) {
            return null;
        }
        $rounded = round($summary['average'] * 2) / 2;
        $stars = [];
        for ($i = 1; $i <= 5; $i++) {
            $stars[] = $rounded >= $i ? 'full' : ($rounded >= $i - 0.5 ? 'half' : 'empty');
        }
        $distribution = [];
        foreach ([5, 4, 3, 2, 1] as $score) {
            $count = (int) ($summary['distribution'][$score] ?? 0);
            $distribution[] = ['score' => $score, 'count' => $count, 'percent' => (int) round($count * 100 / max(1, $summary['count']))];
        }

        return [
            'average' => number_format($summary['average'], 1),
            'count' => $summary['count'],
            'stars' => $stars,
            'show_count' => (bool) ($source['show_count'] ?? true),
            'distribution' => ($source['show_distribution'] ?? false) ? $distribution : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function hours(array $source, SiteRenderContext $ctx): ?array
    {
        $out = [];
        foreach ($this->pick($this->uuids($source['branch_uuids'] ?? []), $ctx) as $branch) {
            $out[] = ['name' => $branch['name'], 'days' => $this->week((array) $branch['hours'], $ctx)];
        }

        return $out === [] ? null : ['branches' => $out, 'single' => count($out) === 1];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function branchCards(array $source, SiteRenderContext $ctx): ?array
    {
        $out = [];
        foreach ($this->pick($this->uuids($source['branch_uuids'] ?? []), $ctx) as $branch) {
            $out[] = [
                'name' => $branch['name'],
                'address' => (string) ($branch['address'] ?? ''),
                'days' => ($source['show_hours'] ?? true) ? $this->week((array) $branch['hours'], $ctx) : [],
                'phone' => ($source['show_contact'] ?? true) ? $ctx->contact('phone', $branch['contact_phone'] ?? null) : null,
                'whatsapp' => ($source['show_contact'] ?? true) ? $ctx->contact('whatsapp', $branch['contact_whatsapp'] ?? null) : null,
                'map_href' => ($source['show_map_link'] ?? true) ? $this->mapLink($branch) : null,
            ];
        }

        return $out === [] ? null : ['items' => $out];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function contact(array $source, SiteRenderContext $ctx): ?array
    {
        $branch = $this->one((string) ($source['branch_uuid'] ?? ''), $ctx);
        if ($branch === null) {
            return null;
        }
        $data = [
            'name' => $branch['name'],
            'address' => ($source['show_address'] ?? true) ? (string) ($branch['address'] ?? '') : '',
            'phone' => ($source['show_phone'] ?? true) ? $ctx->contact('phone', $branch['contact_phone'] ?? null) : null,
            'whatsapp' => ($source['show_whatsapp'] ?? true) ? $ctx->contact('whatsapp', $branch['contact_whatsapp'] ?? null) : null,
            'email' => ($source['show_email'] ?? true) ? $ctx->contact('email', $branch['contact_email'] ?? null) : null,
            'map_href' => ($source['show_map_link'] ?? true) ? $this->mapLink($branch) : null,
        ];

        return $data['address'] === '' && $data['phone'] === null && $data['whatsapp'] === null && $data['email'] === null && $data['map_href'] === null ? null : $data;
    }

    /**
     * The map is framed ONLY from the branch's validated coordinates and the
     * configured provider. A URL the center typed is only ever a link.
     *
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>|null
     */
    private function map(array $source, SiteRenderContext $ctx): ?array
    {
        $branch = $this->one((string) ($source['branch_uuid'] ?? ''), $ctx);
        if ($branch === null) {
            return null;
        }
        $lat = $branch['latitude'] ?? null;
        $lng = $branch['longitude'] ?? null;
        $zoom = (int) ($source['zoom'] ?? 15);
        $embed = null;
        if (($source['mode'] ?? 'link') === 'embed' && is_float($lat) && is_float($lng)) {
            $delta = 360 / (2 ** $zoom) * 1.5;
            $embed = strtr((string) config('site.map.embed'), [
                '{west}' => $this->coordinate($lng - $delta), '{south}' => $this->coordinate($lat - $delta / 2),
                '{east}' => $this->coordinate($lng + $delta), '{north}' => $this->coordinate($lat + $delta / 2),
                '{lat}' => $this->coordinate($lat), '{lng}' => $this->coordinate($lng), '{zoom}' => (string) $zoom,
            ]);
            $embed = str_starts_with($embed, 'https://') ? $embed : null;
        }
        $link = $this->mapLink($branch, $zoom);
        if ($embed === null && $link === null) {
            return null;
        }

        return ['name' => $branch['name'], 'address' => (string) ($branch['address'] ?? ''), 'embed' => $embed, 'link' => $link];
    }

    /**
     * @param  array<string, mixed>  $branch
     */
    private function mapLink(array $branch, int $zoom = 15): ?string
    {
        $lat = $branch['latitude'] ?? null;
        $lng = $branch['longitude'] ?? null;
        if (is_float($lat) && is_float($lng)) {
            $link = strtr((string) config('site.map.link'), ['{lat}' => $this->coordinate($lat), '{lng}' => $this->coordinate($lng), '{zoom}' => (string) $zoom]);

            return str_starts_with($link, 'https://') ? $link : null;
        }
        $url = $branch['map_url'] ?? null;

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * @param  array<int, list<array{opens: string, closes: string}>>  $hours
     * @return list<array{day: string, value: string}>
     */
    private function week(array $hours, SiteRenderContext $ctx): array
    {
        $days = [];
        foreach (self::WEEK as $day) {
            $intervals = array_map(static fn (array $slot): string => $slot['opens'].'–'.$slot['closes'], $hours[$day] ?? []);
            $days[] = [
                'day' => (string) __('center_site.days.'.$day, [], $ctx->locale),
                'value' => $intervals === [] ? (string) __('center_site.hours.closed', [], $ctx->locale) : implode(' · ', $intervals),
            ];
        }

        return $days;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allBranches(SiteRenderContext $ctx): array
    {
        return $this->branches ??= $this->branchReader->branches(null, $ctx->locale);
    }

    /**
     * @param  list<string>  $uuids
     * @return list<array<string, mixed>>
     */
    private function pick(array $uuids, SiteRenderContext $ctx): array
    {
        $all = $this->allBranches($ctx);

        return $uuids === [] ? $all : array_values(array_filter($all, static fn (array $branch): bool => in_array($branch['uuid'], $uuids, true)));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function one(string $uuid, SiteRenderContext $ctx): ?array
    {
        $all = $this->allBranches($ctx);
        if ($uuid === '') {
            return $all[0] ?? null;
        }
        foreach ($all as $branch) {
            if ($branch['uuid'] === $uuid) {
                return $branch;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function uuids(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    private function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}
