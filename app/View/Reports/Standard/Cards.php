<?php

declare(strict_types=1);

namespace App\View\Reports\Standard;

use App\View\Charts\Change;
use App\View\Charts\ValueFormat;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Builds the cards of a Standard report view from its facts: KPI tiles,
 * chart cards (each naming the shared x-chart component and its props) and
 * detail tables with sortable, pre-formatted cells.
 *
 * A card that would draw nothing — every value zero, one lonely slice, no
 * rows — is not built at all: the page never shows a giant empty chart. All
 * wording is translated here by key; units map to the chart library's
 * formats (money in minor units of the lead currency, percentage points,
 * seconds, minutes).
 */
final class Cards
{
    /**
     * @param  list<array{key: string, label: string}>  $buckets
     * @param  list<array{key: string, label: string}>  $previousBuckets
     */
    public function __construct(
        private readonly array $buckets,
        private readonly array $previousBuckets,
        public readonly ?string $currency,
        public readonly string $comparison,
    ) {}

    public static function format(string $unit): string
    {
        return match ($unit) {
            'money' => 'money',
            'percent' => 'percent',
            'seconds' => 'seconds',
            'minutes' => 'duration',
            default => 'number',
        };
    }

    /**
     * A KPI tile for <x-chart.kpi>, or null when the view has no such metric.
     *
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $series
     * @return array<string, mixed>|null
     */
    public function kpi(string $key, array $metrics, array $series = [], ?string $trendKey = null): ?array
    {
        $metric = $metrics[$key] ?? null;

        if (! is_array($metric)) {
            return null;
        }

        $unit = (string) $metric['unit'];

        return [
            'key' => $key,
            'label' => self::label('metrics', $key),
            'current' => $metric['current'],
            'previous' => $metric['previous'],
            'format' => self::format($unit),
            'currency' => $unit === 'money' ? $this->currency : null,
            'better' => $metric['better'],
            'trend' => $this->trend($series[$trendKey ?? $key]['current'] ?? null),
            'help' => self::help($key),
        ];
    }

    /**
     * One series over the period, the previous period dashed beneath it.
     *
     * @param  array{current: list<int|float>|null, previous: list<int|float>|null, unit: string}|null  $series
     * @return array<string, mixed>|null
     */
    public function line(string $id, ?array $series, bool $wide = true): ?array
    {
        if ($series === null || $series['current'] === null) {
            return null;
        }

        $current = $series['current'];
        $previous = $series['previous'];

        if (self::blank($current) && ($previous === null || self::blank($previous))) {
            return null;
        }

        $unit = (string) $series['unit'];
        $format = self::format($unit);
        $currency = $unit === 'money' ? $this->currency : null;
        $title = self::label('charts', $id);
        $known = count($this->previousBuckets);

        return $this->card($id, 'line', [
            'buckets' => $this->labels(),
            'series' => ['label' => $title, 'values' => $current],
            // A shorter previous period has no day 31: a gap, never a zero.
            'previous' => $previous === null ? null : [
                'label' => $this->comparison,
                'values' => array_map(static fn (int|float $value, int $i): int|float|null => $i < $known ? $value : null, $previous, array_keys($previous)),
                'labels' => array_column($this->previousBuckets, 'label'),
            ],
            'format' => $format,
            'currency' => $currency,
            'area' => true,
        ], wide: $wide, badge: $currency, figure: ValueFormat::make($format, $currency)->full(array_sum($current)));
    }

    /**
     * Part-to-whole at a glance — only with at least two real parts.
     *
     * @param  list<array<string, mixed>>  $items  {label, value, status?, color?}
     * @return array<string, mixed>|null
     */
    public function donut(string $id, array $items, string $mode = 'categorical', string $unit = 'number', ?string $total = null): ?array
    {
        $items = array_values(array_filter($items, static fn (array $item): bool => (float) $item['value'] > 0));

        if (count($items) < 2) {
            return null;
        }

        $currency = $unit === 'money' ? $this->currency : null;

        return $this->card($id, 'donut', [
            'items' => $items,
            'mode' => $mode,
            'format' => self::format($unit),
            'currency' => $currency,
            'keep' => 6,
            'total' => $total,
        ], badge: $currency);
    }

    /**
     * One ratio in percentage points, with the previous period's dot.
     *
     * @return array<string, mixed>|null
     */
    public function radial(string $id, ?float $value, ?float $previous, string $tone = 'accent', ?string $caption = null): ?array
    {
        if ($value === null) {
            return null;
        }

        return $this->card($id, 'radial', ['value' => $value, 'previous' => $previous, 'tone' => $tone, 'caption' => $caption]);
    }

    /**
     * Ranked horizontal bars, largest first unless the order IS the meaning.
     *
     * `$keepEmpty` keeps zero rows (a fixed scale such as 1–5 stars must show
     * every step). `$additive` false is for values that cannot be added up —
     * averages, ratings: the tail is never summed into "Other"; the chart
     * names the top `$limit` and the detail table lists every row.
     *
     * @param  list<array<string, mixed>>  $items  {label, value, previous?, meta?, href?}
     * @return array<string, mixed>|null
     */
    public function ranked(string $id, array $items, string $unit = 'number', ?bool $better = true, bool $share = false, bool $sort = true, int $limit = 7, bool $keepEmpty = false, bool $additive = true): ?array
    {
        if (! $keepEmpty) {
            $items = array_values(array_filter($items, static fn (array $item): bool => (float) $item['value'] > 0 || (float) ($item['previous'] ?? 0) > 0));
        }

        $currency = $unit === 'money' ? $this->currency : null;

        if ($items === [] || self::blank(array_map(static fn (array $item): float => (float) $item['value'], $items))) {
            return null;
        }

        if (! $additive) {
            if ($sort) {
                usort($items, static fn (array $a, array $b): int => (float) $b['value'] <=> (float) $a['value']);
            }

            $items = array_slice($items, 0, max(1, $limit));
            $limit = count($items);
        }

        $withPrevious = array_filter($items, static fn (array $item): bool => array_key_exists('previous', $item) && $item['previous'] !== null) !== [];

        return $this->card($id, 'ranked', [
            'items' => $items,
            'format' => self::format($unit),
            'currency' => $currency,
            'limit' => $limit,
            'share' => $share,
            'sort' => $sort,
            'higher_is_better' => $better,
            'previous_label' => $withPrevious ? $this->comparison : null,
        ], badge: $currency);
    }

    /**
     * Parts per bucket over time.
     *
     * @param  list<array<string, mixed>>  $series  {label, values, status?}
     * @return array<string, mixed>|null
     */
    public function stacked(string $id, array $series, string $mode = 'status'): ?array
    {
        $series = array_values(array_filter($series, static fn (array $part): bool => ! self::blank($part['values'])));

        if (count($series) < 2) {
            return null;
        }

        return $this->card($id, 'stacked', ['buckets' => $this->labels(), 'series' => $series, 'mode' => $mode], wide: true);
    }

    /**
     * @param  list<string>  $rows
     * @param  list<string>  $columns
     * @param  list<list<int>>  $values
     * @return array<string, mixed>|null
     */
    public function heatmap(string $id, array $rows, array $columns, array $values, string $rowHeader, string $columnHeader): ?array
    {
        if ($columns === [] || self::blank(array_merge(...$values))) {
            return null;
        }

        return $this->card($id, 'heatmap', [
            'rows' => $rows,
            'columns' => $columns,
            'values' => $values,
            'row_header' => $rowHeader,
            'column_header' => $columnHeader,
        ], wide: true);
    }

    /**
     * 2–4 series side by side per group (this period against the previous).
     *
     * @param  list<string>  $groups
     * @param  list<array{label: string, values: list<int|float>}>  $series
     * @return array<string, mixed>|null
     */
    public function grouped(string $id, array $groups, array $series, string $unit = 'number'): ?array
    {
        if ($groups === [] || self::blank(array_merge(...array_map(static fn (array $part): array => $part['values'], $series)))) {
            return null;
        }

        $currency = $unit === 'money' ? $this->currency : null;

        return $this->card($id, 'grouped', [
            'groups' => $groups,
            'series' => $series,
            'format' => self::format($unit),
            'currency' => $currency,
        ], badge: $currency);
    }

    /**
     * Columns over named buckets (an ordinal scale such as visits per customer).
     *
     * @param  list<string>  $buckets
     * @param  list<array{label: string, values: list<int|float>}>  $series
     * @return array<string, mixed>|null
     */
    public function columns(string $id, array $buckets, array $series): ?array
    {
        if ($buckets === [] || self::blank(array_merge(...array_map(static fn (array $part): array => $part['values'], $series)))) {
            return null;
        }

        return $this->card($id, 'columns', ['buckets' => array_map(static fn (string $label): array => ['label' => $label], $buckets), 'series' => $series]);
    }

    /**
     * A detail table: sortable columns, formatted cells, client-side pages.
     *
     * @param  list<array{key: string, numeric?: bool}>  $columns
     * @param  list<list<array<string, mixed>>>  $rows  cells from cell() / money() / change()
     * @return array<string, mixed>|null
     */
    public function table(string $id, array $columns, array $rows, int $pageSize = 10): ?array
    {
        if ($rows === []) {
            return null;
        }

        $columns = array_map(static fn (array $column): array => [
            'key' => $column['key'],
            'label' => self::label('columns', $column['key']),
            'numeric' => (bool) ($column['numeric'] ?? false),
        ], $columns);

        return $this->card($id, 'table', [
            'columns' => $columns,
            'rows' => $rows,
            'page_size' => $pageSize,
            'caption' => trans_choice('manager_reports.sections.rows', count($rows), ['count' => number_format(count($rows))]),
            // A new data set gets a fresh table (and a fresh sort state).
            'hash' => substr(sha1((string) json_encode($rows)), 0, 12),
        ], wide: true);
    }

    /** @return array{text: string, sort: string|int|float, href: string|null} */
    public static function cell(string $text, string|int|float|null $sort = null, ?string $href = null): array
    {
        return ['text' => $text, 'sort' => $sort ?? mb_strtolower($text), 'href' => $href];
    }

    /** @return array{text: string, sort: int|float|string, href: null} */
    public function number(int|float|null $value, string $unit = 'number'): array
    {
        $format = ValueFormat::make(self::format($unit), $unit === 'money' ? $this->currency : null);

        return ['text' => $format->full($value), 'sort' => $value ?? '', 'href' => null];
    }

    /**
     * The change between two values, toned by what the metric counts as
     * better; no percentage from a zero base.
     *
     * @return array{text: string, sort: int|float, tone: string, href: null}
     */
    public function change(int|float $current, int|float|null $previous, string $unit = 'number', ?bool $better = true): array
    {
        $change = Change::between($current, $previous, $better);
        $format = ValueFormat::make(self::format($unit), $unit === 'money' ? $this->currency : null);

        return [
            'text' => $change->direction === 'none' ? '—' : $change->label($format),
            'sort' => $change->percent ?? (float) ($change->difference ?? 0),
            'tone' => $change->tone,
            'href' => null,
        ];
    }

    public static function label(string $group, string $key): string
    {
        return (string) __('manager_reports.std.'.$group.'.'.$key);
    }

    /**
     * A stored value (a source, a kind, a state) in the viewer's language:
     * this page's own labels first, then the shared report and center
     * labels, and a readable heading rather than a raw code as the last word.
     */
    public static function value(string $group, string $value): string
    {
        foreach (['manager_reports.std.values.'.$group.'.'.$value, 'manager_reports.values.'.$group.'.'.$value, 'labels.'.$group.'.'.$value] as $key) {
            if (Lang::has($key)) {
                return (string) __($key);
            }
        }

        return $value === '' ? (string) __('manager_reports.values.method_other') : Str::headline($value);
    }

    /**
     * Keeps the sections that still have cards, in the page's reading order:
     * trends, distribution, comparison, then the details.
     *
     * @param  array<string, list<array<string, mixed>|null>>  $sections
     * @return list<array{key: string, title: string, cards: list<array<string, mixed>>}>
     */
    public static function sections(array $sections): array
    {
        $kept = [];

        foreach ($sections as $key => $cards) {
            $cards = array_values(array_filter($cards));

            if ($cards !== []) {
                $kept[] = ['key' => $key, 'title' => self::label('sections', $key), 'cards' => $cards];
            }
        }

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>|null>  $kpis
     * @return list<array<string, mixed>>
     */
    public static function kpis(array $kpis): array
    {
        return array_values(array_filter($kpis));
    }

    /** A definition worth a tooltip, when the metric has one. */
    public static function help(string $key): ?string
    {
        foreach (['manager_reports.std.help.'.$key, 'manager_reports.glossary.'.$key] as $candidate) {
            if (Lang::has($candidate)) {
                return (string) __($candidate);
            }
        }

        return null;
    }

    /** @return list<string> */
    private function labels(): array
    {
        return array_column($this->buckets, 'label');
    }

    /**
     * A sparkline only where there is a shape to show.
     *
     * @param  list<int|float>|null  $values
     * @return list<int|float>
     */
    private function trend(?array $values): array
    {
        return $values !== null && count($values) > 1 && ! self::blank($values) ? $values : [];
    }

    /** @param list<int|float|null> $values */
    private static function blank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && (float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function card(string $id, string $type, array $props, bool $wide = false, ?string $badge = null, ?string $figure = null): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => self::label('charts', $id),
            'help' => self::help('chart_'.$id),
            'wide' => $wide,
            'badge' => $badge,
            'figure' => $figure,
            'props' => $props,
        ];
    }
}
