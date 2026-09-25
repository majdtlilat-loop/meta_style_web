<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.multiline>: 2–4 series over the same time axis
 * (branch vs branch, employee vs employee — a trend OVERLAY). One unit, one
 * y-axis; a crosshair per bucket lists every series ("one tooltip, every
 * series"). Past four series, three stay and the rest are summed into Other
 * (muted), like <x-chart.grouped>.
 *
 * Geometry matches <x-chart.line>: a 1000 × 300 viewBox stretched to the plot
 * with non-scaling strokes, points at bucket centres, so the HTML hit columns,
 * markers and axis labels mirror together in right-to-left layouts.
 */
final class MultiLineChart
{
    public const MAX_SERIES = 4;

    private const W = 1000.0;

    private const H = 300.0;

    /**
     * @param  array<mixed>  $buckets  strings or ['label' => string]
     * @param  array<mixed>  $series  each ['label' => string, 'values' => list<int|float|null>, 'color' => int|string|null]
     * @return array<string, mixed>
     */
    public static function build(string $label, array $buckets, array $series, ?string $format, ?string $currency, ?bool $totals = null): array
    {
        $fmt = ValueFormat::make($format, $currency);
        $labels = Axis::labels($buckets);
        $count = count($labels);

        $clean = [];
        foreach (array_values($series) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $clean[] = [
                'label' => ChartData::text($s['label'] ?? null),
                'values' => ChartData::values($s['values'] ?? [], $count),
                'color' => $s['color'] ?? null,
            ];
        }

        $keep = count($clean) > self::MAX_SERIES ? self::MAX_SERIES - 1 : self::MAX_SERIES;
        $folded = Fold::series($clean, $keep, (string) __('manager_charts.other'));
        $lines = [];
        foreach ($folded as $i => $s) {
            $lines[] = [
                'label' => (string) $s['label'],
                'series' => $s['other'] ? 'other' : Palette::resolve($s['color'] ?? null, Palette::slot($i)),
                'values' => ChartData::values($s['values'] ?? [], $count),
                'members' => array_map(static fn (array $m): string => ChartData::text($m['label'] ?? null), (array) ($s['members'] ?? [])),
            ];
        }

        $all = array_merge([], ...array_map(static fn (array $line): array => $line['values'], $lines));
        $empty = $count === 0 || $lines === [] || ChartData::blank($all);
        $totals ??= ChartData::additive($fmt);

        $known = array_values(array_filter($all, static fn ($v): bool => $v !== null));
        $min = $known === [] ? 0.0 : (float) min($known);
        $max = $known === [] ? 0.0 : (float) max($known);
        $scale = $fmt->kind === 'percent' && $min >= 0 ? Scale::percent($max) + ['min' => 0.0] : Scale::range($min, $max);
        $lo = $scale['min'];
        $hi = $scale['max'];

        $x = static fn (int $i): float => ($i + 0.5) / max(1, $count) * self::W;
        $y = static fn (float $v): float => self::H - Scale::position($v, $lo, $hi) / 100 * self::H;

        $points = [];
        $table = [];
        foreach ($labels as $i => $bucketLabel) {
            $rows = [];
            $aria = [];
            $dots = [];
            $cells = [];
            foreach ($lines as $line) {
                $value = $line['values'][$i];
                $rows[] = ChartData::row($line['series'], $line['label'], $fmt->full($value));
                $aria[] = $line['label'].' '.$fmt->full($value);
                $cells[] = $fmt->full($value);
                if ($value !== null) {
                    $dots[] = ['series' => $line['series'], 'pos' => Scale::position((float) $value, $lo, $hi)];
                }
            }
            $points[] = ['label' => $bucketLabel, 'rows' => $rows, 'aria' => $bucketLabel.': '.implode('; ', $aria), 'dots' => $dots];
            $table[] = ['label' => $bucketLabel, 'values' => $cells];
        }

        $summary = '';
        if (! $empty) {
            $parts = [];
            foreach ($lines as $line) {
                $values = $line['values'];
                $present = array_filter($values, static fn ($v): bool => $v !== null);
                if ($present === []) {
                    continue;
                }
                $highAt = (int) array_search(max($present), $values, true);
                $lowAt = (int) array_search(min($present), $values, true);
                $parts[] = (string) __('manager_charts.summary.trend', [
                    'label' => $line['label'],
                    'from' => $labels[0],
                    'to' => $labels[$count - 1],
                    'high' => $fmt->full($values[$highAt]),
                    'high_at' => $labels[$highAt],
                    'low' => $fmt->full($values[$lowAt]),
                    'low_at' => $labels[$lowAt],
                ]);
            }
            $summary = $label.': '.implode(' ', $parts);
        }

        return [
            'id' => ChartData::id('multiline'),
            'empty' => $empty,
            'legend' => array_map(static fn (array $line): array => ['label' => $line['label'], 'series' => $line['series'], 'members' => $line['members']], $lines),
            'paths' => array_map(static fn (array $line): array => ['series' => $line['series'], 'd' => self::path($line['values'], $x, $y)], $lines),
            'ticks' => array_map(static fn (float $tick): array => ['label' => $fmt->tick($tick), 'pos' => Scale::position($tick, $lo, $hi)], $scale['ticks']),
            'zero' => $lo < 0 ? Scale::position(0.0, $lo, $hi) : null,
            'axis' => Axis::ticks($labels),
            'points' => $points,
            'table' => $table,
            'totals' => $totals ? array_map(static fn (array $line): string => $fmt->full(array_sum(array_map(static fn ($v): float => (float) $v, $line['values']))), $lines) : null,
            'summary' => $summary,
            'view_box' => '0 0 '.(int) self::W.' '.(int) self::H,
        ];
    }

    /**
     * @param  list<int|float|null>  $values
     * @param  callable(int): float  $x
     * @param  callable(float): float  $y
     */
    private static function path(array $values, callable $x, callable $y): string
    {
        $d = '';
        $pen = false;

        foreach ($values as $i => $value) {
            if ($value === null) {
                $pen = false;

                continue;
            }

            $d .= ($pen ? 'L' : 'M').self::n($x($i)).' '.self::n($y((float) $value));
            $pen = true;
        }

        return $d;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
