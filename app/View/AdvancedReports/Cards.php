<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use App\View\Charts\Change;
use App\View\Charts\ValueFormat;

/**
 * Card shapes for the Advanced page. A card is `type` + the props of the
 * chart component that draws it (`resources/views/livewire/center/
 * advanced-reports/card.blade.php` switches on the type), plus its `title`
 * and grid `span` (third | half | two-thirds | full).
 */
final class Cards
{
    /**
     * @param  list<array{label: string}>  $buckets
     * @param  list<int|float>  $values
     * @param  list<int|float>  $previous
     * @param  list<string>  $previousLabels
     * @return array<string, mixed>|null null when neither period has a value
     */
    public static function line(string $title, array $buckets, array $values, array $previous, array $previousLabels, string $format = 'number', ?string $currency = null, string $span = 'two-thirds'): ?array
    {
        return Series::blank($values) && Series::blank($previous) ? null : [
            'type' => 'line',
            'title' => $title,
            'span' => $span,
            'badge' => $currency,
            'buckets' => $buckets,
            'series' => ['label' => $title, 'values' => $values],
            'previous' => ['label' => (string) __('manager_advanced.period.comparison_series'), 'values' => $previous, 'labels' => $previousLabels],
            'format' => $format,
            'currency' => $currency,
        ];
    }

    /**
     * Parts per time bucket (x-chart.stacked). A part that is zero in every
     * bucket is left out; nothing at all is no card.
     *
     * @param  list<array{label: string}>  $buckets
     * @param  list<array{label: string, values: list<int|float>, status?: string}>  $series
     * @return array<string, mixed>|null
     */
    public static function stacked(string $title, array $buckets, array $series, string $mode = 'categorical', ?string $format = null, ?string $currency = null, string $span = 'two-thirds'): ?array
    {
        $series = array_values(array_filter($series, static fn (array $part): bool => ! Series::blank($part['values'])));

        return $series === [] ? null : [
            'type' => 'stacked',
            'title' => $title,
            'span' => $span,
            'badge' => $currency,
            'buckets' => $buckets,
            'series' => $series,
            'mode' => $mode,
            'format' => $format,
            'currency' => $currency,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    public static function donut(string $title, array $items, string $mode = 'categorical', ?string $format = null, ?string $currency = null, int $keep = 6, string $span = 'third'): ?array
    {
        // A part-to-whole needs parts: one slice (or none) says nothing a figure would not.
        $parts = count(array_filter($items, static fn (array $item): bool => Series::num($item['value'] ?? 0) > 0));

        return $parts < 2 ? null : [
            'type' => 'donut',
            'title' => $title,
            'span' => $span,
            'badge' => $currency,
            'items' => $items,
            'mode' => $mode,
            'format' => $format,
            'currency' => $currency,
            'keep' => $keep,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items  label, value, previous?
     * @return array<string, mixed>|null
     */
    public static function ranked(string $title, array $items, ?string $format = null, ?string $currency = null, bool $share = false, ?bool $higher = true, string $span = 'half', int $limit = 7, bool $sort = true): ?array
    {
        $items = array_values(array_filter($items, static fn (array $item): bool => Series::num($item['value'] ?? 0) > 0 || Series::num($item['previous'] ?? 0) > 0));

        return $items === [] ? null : [
            'type' => 'ranked',
            'title' => $title,
            'span' => $span,
            'badge' => $currency,
            'items' => $items,
            'format' => $format,
            'currency' => $currency,
            'share' => $share,
            'higher' => $higher,
            'limit' => $limit,
            'sort' => $sort,
            'previous_label' => (string) __('manager_advanced.period.comparison_series'),
        ];
    }

    /**
     * One ratio with its comparison value; the caption states the change
     * toned by the metric's own direction.
     *
     * @return array<string, mixed>|null
     */
    public static function radial(string $label, ?float $value, ?float $previous, ?bool $higher, string $tone = 'accent', int|float $max = 100, string $format = 'percent', ?string $display = null): ?array
    {
        if ($value === null) {
            return null;
        }

        $fmt = ValueFormat::make($format);
        $change = Change::between($value, $previous, $higher);

        return [
            'label' => $label,
            'value' => $value,
            'previous' => $previous,
            'max' => $max,
            'format' => $format,
            'tone' => $tone,
            'display' => $display,
            'caption' => $change->direction === 'none' ? null : $change->label($fmt),
            'change_tone' => $change->tone,
        ];
    }

    /**
     * @param  list<array<string, mixed>|null>  $radials
     * @return array<string, mixed>|null
     */
    public static function radials(string $title, array $radials, string $span = 'third'): ?array
    {
        $radials = array_values(array_filter($radials));

        return $radials === [] ? null : ['type' => 'radials', 'title' => $title, 'span' => $span, 'badge' => null, 'items' => $radials];
    }

    /**
     * Compact KPI tiles (x-chart.kpi) inside one card.
     *
     * @param  list<array<string, mixed>|null>  $metrics  Metrics::card() results
     * @return array<string, mixed>|null
     */
    public static function stats(string $title, array $metrics, string $span = 'third'): ?array
    {
        $metrics = array_values(array_filter($metrics));

        return $metrics === [] ? null : ['type' => 'stats', 'title' => $title, 'span' => $span, 'badge' => null, 'items' => $metrics];
    }

    /**
     * @param  array{rows: list<string>, columns: list<string>, values: list<list<int>>}|null  $grid
     * @return array<string, mixed>|null
     */
    public static function heatmap(string $title, ?array $grid, string $span = 'full'): ?array
    {
        return $grid === null ? null : [
            'type' => 'heatmap',
            'title' => $title,
            'span' => $span,
            'badge' => null,
            'rows' => $grid['rows'],
            'columns' => $grid['columns'],
            'values' => $grid['values'],
            'row_header' => (string) __('manager_advanced.axis.day'),
            'column_header' => (string) __('manager_advanced.axis.hour'),
        ];
    }

    /**
     * @param  list<string>  $groups
     * @param  list<array{label: string, values: list<int|float>}>  $series
     * @return array<string, mixed>|null
     */
    public static function grouped(string $title, array $groups, array $series, ?string $format = null, ?string $currency = null, string $span = 'half'): ?array
    {
        $all = array_merge([], ...array_map(static fn (array $s): array => $s['values'], $series));

        return $groups === [] || Series::blank($all) ? null : [
            'type' => 'grouped',
            'title' => $title,
            'span' => $span,
            'badge' => $currency,
            'groups' => $groups,
            'series' => $series,
            'format' => $format,
            'currency' => $currency,
        ];
    }

    /**
     * @param  list<list<int|float>>  $values
     * @param  list<string>  $labels
     * @param  list<array{label: string}>  $buckets
     * @return array<string, mixed>|null
     */
    public static function columns(string $title, array $buckets, array $labels, array $values, ?string $format = null, ?string $currency = null, string $span = 'two-thirds'): ?array
    {
        $all = array_merge([], ...$values);

        return Series::blank($all) ? null : [
            'type' => 'columns',
            'title' => $title,
            'span' => $span,
            'badge' => $currency,
            'buckets' => $buckets,
            'series' => array_map(static fn (string $label, array $series): array => ['label' => $label, 'values' => $series], $labels, $values),
            'format' => $format,
            'currency' => $currency,
        ];
    }

    /**
     * A table card: `columns` (label + numeric flag) and rows of formatted
     * text cells. `sort` (optional, same shape) holds each cell's raw value,
     * so the browser sorts numbers as numbers; a null sorts last.
     *
     * @param  list<array{label: string, numeric: bool}>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<list<int|float|string|null>>|null  $sort
     * @return array<string, mixed>|null
     */
    public static function table(string $title, array $columns, array $rows, string $span = 'full', ?array $sort = null): ?array
    {
        return $rows === [] ? null : ['type' => 'table', 'title' => $title, 'span' => $span, 'badge' => null, 'columns' => $columns, 'rows' => $rows, 'sort' => $sort ?? $rows, 'page_size' => 10];
    }
}
