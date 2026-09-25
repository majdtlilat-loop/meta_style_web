<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.grouped>: 2–4 entities side by side per group
 * (branch vs branch per week, employee vs employee per metric). One y-axis
 * only, so every value in the chart must share one unit — compare metrics of
 * different units in separate charts.
 *
 * Past four entities, the first three stay and the rest fold into "Other"
 * (pass them in the order that matters — largest, or the one being judged).
 */
final class GroupedChart
{
    public const MAX_SERIES = 4;

    /**
     * @param  array<mixed>  $groups
     * @param  array<mixed>  $series  (untyped Blade input) each ['label' => string, 'values' => list<int|float|null>, 'color' => int|string|null]
     * @return array<string, mixed>
     */
    public static function build(string $label, array $groups, array $series, ?string $format, ?string $currency): array
    {
        $fmt = ValueFormat::make($format, $currency);
        $labels = Axis::labels($groups);
        $count = count($labels);

        $clean = [];
        foreach (array_values($series) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $clean[] = [
                'label' => ChartData::text($s['label'] ?? null),
                'values' => array_map(static fn ($v): int|float => ChartData::magnitude($v), ChartData::values($s['values'] ?? [], $count)),
                'color' => $s['color'] ?? null,
            ];
        }

        // Never more than four bars per group: past four, three plus Other.
        $keep = count($clean) > self::MAX_SERIES ? self::MAX_SERIES - 1 : self::MAX_SERIES;
        $folded = Fold::series($clean, $keep, (string) __('manager_charts.other'));
        $legend = [];
        foreach ($folded as $i => $s) {
            $legend[] = [
                'label' => (string) $s['label'],
                'series' => $s['other'] ? 'other' : Palette::resolve($s['color'] ?? null, Palette::slot($i)),
            ];
        }

        /** @var list<list<int|float>> $matrix */
        $matrix = array_map(static fn (array $s): array => array_values((array) $s['values']), $folded);
        $all = $matrix === [] ? [0] : array_merge([0], ...$matrix);
        $max = (float) max($all);
        $scale = $fmt->kind === 'percent' ? Scale::percent($max) : Scale::nice($max);

        $columns = [];
        foreach ($labels as $g => $groupLabel) {
            $bars = [];
            $rows = [];
            $aria = [];
            foreach ($folded as $i => $s) {
                $value = $matrix[$i][$g] ?? 0;
                $bars[] = ['series' => $legend[$i]['series'], 'height' => Scale::position((float) $value, 0.0, $scale['max']), 'empty' => $value <= 0];
                $rows[] = ChartData::row($legend[$i]['series'], $legend[$i]['label'], $fmt->full($value));
                $aria[] = $legend[$i]['label'].' '.$fmt->full($value);
            }
            $columns[] = ['label' => $groupLabel, 'bars' => $bars, 'rows' => $rows, 'aria' => $groupLabel.': '.implode(', ', $aria)];
        }

        $empty = $count === 0 || $max <= 0;

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
            'id' => ChartData::id('grouped'),
            'empty' => $empty,
            'legend' => $legend,
            'columns' => $columns,
            'ticks' => array_map(static fn (float $tick): array => ['label' => $fmt->tick($tick), 'pos' => Scale::position($tick, 0.0, $scale['max'])], $scale['ticks']),
            'axis' => Axis::ticks($labels),
            'table' => array_map(static fn (string $groupLabel, int $g): array => [
                'label' => $groupLabel,
                'values' => array_map(static fn (array $values): string => $fmt->full($values[$g] ?? 0), $matrix),
            ], $labels, array_keys($labels)),
            'members' => $members,
            'summary' => $empty ? '' : (string) __('manager_charts.summary.grouped', [
                'label' => $label,
                'series' => implode(', ', array_map(static fn (array $l): string => $l['label'], $legend)),
                'count' => $count,
            ]),
        ];
    }
}
