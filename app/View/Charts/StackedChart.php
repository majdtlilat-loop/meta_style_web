<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.stacked>: columns over time split into parts
 * (bookings by status per day, sales by category per week). Segments are
 * separated by a 2px surface gap; the legend is always shown; each bucket is
 * one hover / focus target listing every part and the total.
 *
 * More than 8 series fold into "Other" (the ninth colour does not exist).
 */
final class StackedChart
{
    /**
     * @param  array<mixed>  $buckets
     * @param  array<mixed>  $series  (untyped Blade input) each ['label' => string, 'values' => list<int|float|null>, 'status' => ?string, 'tone' => ?string, 'color' => int|string|null]
     * @return array<string, mixed>
     */
    public static function build(string $label, array $buckets, array $series, string $mode, ?string $format, ?string $currency): array
    {
        $fmt = ValueFormat::make($format, $currency);
        $status = $mode === 'status';
        $labels = Axis::labels($buckets);
        $count = count($labels);

        $clean = [];
        foreach (array_values($series) as $index => $s) {
            if (! is_array($s)) {
                continue;
            }
            $clean[] = [
                'label' => ChartData::text($s['label'] ?? null),
                'values' => array_map(static fn ($v): int|float => ChartData::magnitude($v), ChartData::values($s['values'] ?? [], $count)),
                'tone' => $status ? Palette::statusTone(ChartData::text($s['tone'] ?? null) ?: ChartData::text($s['status'] ?? null)) : null,
                'color' => $s['color'] ?? null,
                'order' => $index,
            ];
        }

        if ($status) {
            usort($clean, static fn (array $a, array $b): int => [Palette::statusRank((string) $a['tone']), $a['order']] <=> [Palette::statusRank((string) $b['tone']), $b['order']]);
        }

        $folded = Fold::series($clean, Palette::SLOTS, (string) __('manager_charts.other'));
        $statusIds = $status ? Palette::statusSeries(array_map(static fn (array $s): string => (string) ($s['tone'] ?? 'neutral'), $folded)) : [];

        $legend = [];
        foreach ($folded as $i => $s) {
            $legend[] = [
                'label' => (string) $s['label'],
                'series' => match (true) {
                    (bool) $s['other'] => 'other',
                    $status => $statusIds[$i],
                    default => Palette::resolve($s['color'] ?? null, Palette::slot($i)),
                },
            ];
        }

        /** @var list<list<int|float>> $matrix */
        $matrix = array_map(static fn (array $s): array => array_values((array) $s['values']), $folded);
        $totals = [];
        for ($b = 0; $b < $count; $b++) {
            $totals[] = array_sum(array_map(static fn (array $values): int|float => $values[$b] ?? 0, $matrix));
        }

        $empty = $count === 0 || ChartData::blank($totals);
        $scale = $fmt->kind === 'percent' ? Scale::percent((float) max([0, ...$totals])) : Scale::nice((float) max([0, ...$totals]));

        $columns = [];
        foreach ($labels as $b => $bucketLabel) {
            $segments = [];
            $rows = [];
            $aria = [];
            foreach ($folded as $i => $s) {
                $value = $matrix[$i][$b] ?? 0;
                if ($value > 0) {
                    $segments[] = ['series' => $legend[$i]['series'], 'grow' => self::grow($value)];
                }
                $rows[] = ChartData::row($legend[$i]['series'], $legend[$i]['label'], $fmt->full($value));
                $aria[] = $legend[$i]['label'].' '.$fmt->full($value);
            }
            if (ChartData::additive($fmt) || $fmt->kind === 'percent') {
                $rows[] = ChartData::row('total', (string) __('manager_charts.total'), $fmt->full($totals[$b]));
                $aria[] = __('manager_charts.total').' '.$fmt->full($totals[$b]);
            }

            $columns[] = [
                'label' => $bucketLabel,
                'height' => Scale::position((float) $totals[$b], 0.0, $scale['max']),
                'segments' => $segments,
                'rows' => $rows,
                'aria' => $bucketLabel.': '.implode(', ', $aria),
            ];
        }

        $table = [];
        foreach ($labels as $b => $bucketLabel) {
            $table[] = [
                'label' => $bucketLabel,
                'values' => array_map(static fn (array $values): string => $fmt->full($values[$b] ?? 0), $matrix),
                'total' => $fmt->full($totals[$b]),
            ];
        }

        $members = [];
        foreach ($folded as $s) {
            foreach ((array) ($s['members'] ?? []) as $member) {
                $members[] = [
                    'label' => ChartData::text($member['label'] ?? null),
                    'values' => array_map(static fn ($v): string => $fmt->full(ChartData::magnitude($v)), ChartData::values($member['values'] ?? [], $count)),
                ];
            }
        }

        return [
            'id' => ChartData::id('stacked'),
            'empty' => $empty,
            'legend' => $legend,
            'columns' => $columns,
            'ticks' => array_map(static fn (float $tick): array => ['label' => $fmt->tick($tick), 'pos' => Scale::position($tick, 0.0, $scale['max'])], $scale['ticks']),
            'axis' => Axis::ticks($labels),
            'table' => $table,
            'members' => $members,
            'column_totals' => array_map(static fn (array $values): string => $fmt->full(array_sum($values)), $matrix),
            'grand_total' => $fmt->full(array_sum($totals)),
            'summary' => $empty ? '' : (string) __('manager_charts.summary.stacked', [
                'label' => $label,
                'count' => count($folded),
                'from' => $labels[0],
                'to' => $labels[$count - 1] ?? '',
                'total' => $fmt->full(array_sum($totals)),
            ]),
        ];
    }

    /** A flex-grow weight: proportional, and never in exponent notation. */
    public static function grow(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }
}
