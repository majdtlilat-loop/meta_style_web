<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.line>: one series over time, an optional previous
 * period (dashed, muted) aligned position by position, an optional area wash.
 *
 * The marks are one SVG on a 1000 × 300 viewBox stretched to the plot
 * (non-scaling 2px strokes); points sit at bucket CENTRES so they line up with
 * the HTML hit columns, markers and axis labels, which lay out with flexbox
 * and therefore mirror in right-to-left layouts together with the SVG.
 */
final class LineChart
{
    private const W = 1000.0;

    private const H = 300.0;

    /**
     * @param  array<mixed>  $buckets  strings or ['label' => string]
     * @param  array<string, mixed>  $series  ['label' => string, 'values' => list<int|float|null>]
     * @param  array<string, mixed>|null  $previous  ['label' => ?string, 'values' => list, 'labels' => ?list<string>, 'buckets' => ?list]
     * @return array<string, mixed>
     */
    public static function build(string $label, array $buckets, array $series, ?array $previous, ?string $format, ?string $currency, bool $area = false, ?bool $totals = null): array
    {
        $fmt = ValueFormat::make($format, $currency);
        $labels = Axis::labels($buckets);
        $count = count($labels);
        $current = ChartData::values($series['values'] ?? [], $count);
        $seriesLabel = ChartData::text($series['label'] ?? null, $label) ?: $label;

        $hasPrevious = is_array($previous) && is_array($previous['values'] ?? null);
        $before = $hasPrevious ? PeriodAlign::byPosition(ChartData::values($previous['values'] ?? [], count((array) $previous['values'])), $count) : [];
        $previousLabel = $hasPrevious ? (ChartData::text($previous['label'] ?? null) ?: (string) __('manager_charts.previous')) : '';
        $beforeLabels = [];
        if ($hasPrevious) {
            $explicit = $previous['labels'] ?? ($previous['buckets'] ?? []);
            $beforeLabels = PeriodAlign::labels(is_array($explicit) ? array_values(array_filter($explicit, static fn ($b): bool => is_string($b) || is_array($b))) : [], $count);
        }

        $empty = $count === 0 || ChartData::blank($current, $before);
        $totals ??= ChartData::additive($fmt);

        $known = array_values(array_filter([...$current, ...$before], static fn ($v): bool => $v !== null));
        $min = $known === [] ? 0.0 : (float) min($known);
        $max = $known === [] ? 0.0 : (float) max($known);

        if ($fmt->kind === 'percent' && $min >= 0) {
            $scale = Scale::percent($max) + ['min' => 0.0];
        } else {
            $scale = Scale::range($min, $max);
        }
        $lo = $scale['min'];
        $hi = $scale['max'];

        $x = static fn (int $i): float => ($i + 0.5) / max(1, $count) * self::W;
        $y = static fn (float $v): float => self::H - Scale::position($v, $lo, $hi) / 100 * self::H;
        $baseline = $y(max($lo, min(0.0, $hi)));

        $points = [];
        $maxAt = null;
        $minAt = null;
        foreach ($current as $i => $value) {
            if ($value !== null) {
                $maxAt = $maxAt === null || $value > $current[$maxAt] ? $i : $maxAt;
                $minAt = $minAt === null || $value < $current[$minAt] ? $i : $minAt;
            }
        }
        $lastIndex = null;
        foreach ($current as $i => $value) {
            if ($value !== null) {
                $lastIndex = $i;
            }
        }

        foreach ($labels as $i => $bucketLabel) {
            $now = $current[$i];
            $then = $hasPrevious ? $before[$i] : null;
            $rows = [ChartData::row('1', $seriesLabel, $fmt->full($now))];
            $aria = $bucketLabel.': '.$seriesLabel.' '.$fmt->full($now);

            if ($hasPrevious) {
                $thenLabel = $previousLabel.(isset($beforeLabels[$i]) ? ' · '.$beforeLabels[$i] : '');
                $rows[] = ChartData::row('previous', $thenLabel, $fmt->full($then));
                $aria .= '; '.$thenLabel.' '.$fmt->full($then);

                if ($now !== null && $then !== null) {
                    $change = Change::between($now, $then, null)->label($fmt);
                    if ($change !== '') {
                        $rows[] = ChartData::row('total', (string) __('manager_charts.change'), $change);
                        $aria .= '; '.__('manager_charts.change').' '.$change;
                    }
                }
            }

            $points[] = [
                'label' => $bucketLabel,
                'current' => $now === null ? null : Scale::position((float) $now, $lo, $hi),
                'previous' => $then === null ? null : Scale::position((float) $then, $lo, $hi),
                'isolated' => $now !== null && ($current[$i - 1] ?? null) === null && ($current[$i + 1] ?? null) === null,
                'end' => $i === $lastIndex,
                'rows' => $rows,
                'aria' => $aria,
            ];
        }

        $table = [];
        foreach ($labels as $i => $bucketLabel) {
            $now = $current[$i];
            $then = $hasPrevious ? $before[$i] : null;
            $table[] = [
                'label' => $bucketLabel,
                'current' => $fmt->full($now),
                'previous' => $hasPrevious ? $fmt->full($then) : null,
                'previous_label' => $beforeLabels[$i] ?? null,
                'change' => $hasPrevious && $now !== null && $then !== null ? Change::between($now, $then, null)->label($fmt) : null,
            ];
        }

        $sumNow = array_sum(array_map(static fn ($v): float => (float) $v, $current));
        $sumThen = array_sum(array_map(static fn ($v): float => (float) $v, $before));

        $summary = $empty ? '' : (string) __('manager_charts.summary.trend', [
            'label' => $seriesLabel,
            'from' => $labels[0],
            'to' => $labels[$count - 1] ?? '',
            'high' => $fmt->full($maxAt === null ? null : $current[$maxAt]),
            'high_at' => $maxAt === null ? '' : $labels[$maxAt],
            'low' => $fmt->full($minAt === null ? null : $current[$minAt]),
            'low_at' => $minAt === null ? '' : $labels[$minAt],
        ]);
        if (! $empty && $totals) {
            $summary .= ' '.__('manager_charts.summary.total', ['total' => $fmt->full($sumNow)]);
            if ($hasPrevious) {
                $summary .= ' '.__('manager_charts.summary.previous_total', ['total' => $fmt->full($sumThen)]);
            }
        }

        return [
            'id' => ChartData::id('line'),
            'empty' => $empty,
            'series_label' => $seriesLabel,
            'previous_label' => $previousLabel,
            'has_previous' => $hasPrevious,
            'line' => self::path($current, $x, $y),
            'previous_line' => $hasPrevious ? self::path($before, $x, $y) : '',
            'area' => $area ? self::area($current, $x, $y, $baseline) : '',
            'ticks' => array_map(static fn (float $tick): array => ['label' => $fmt->tick($tick), 'pos' => Scale::position($tick, $lo, $hi)], $scale['ticks']),
            'zero' => $lo < 0 ? Scale::position(0.0, $lo, $hi) : null,
            'axis' => Axis::ticks($labels),
            'points' => $points,
            'table' => $table,
            'totals' => $totals ? ['current' => $fmt->full($sumNow), 'previous' => $hasPrevious ? $fmt->full($sumThen) : null] : null,
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

    /**
     * The wash under the line, one closed shape per unbroken run.
     *
     * @param  list<int|float|null>  $values
     * @param  callable(int): float  $x
     * @param  callable(float): float  $y
     */
    private static function area(array $values, callable $x, callable $y, float $baseline): string
    {
        $d = '';
        $run = [];

        foreach ([...$values, null] as $i => $value) {
            if ($value !== null) {
                $run[] = [$x($i), $y((float) $value)];

                continue;
            }

            if (count($run) > 1) {
                $d .= 'M'.self::n($run[0][0]).' '.self::n($baseline);
                foreach ($run as [$px, $py]) {
                    $d .= 'L'.self::n($px).' '.self::n($py);
                }
                $d .= 'L'.self::n($run[count($run) - 1][0]).' '.self::n($baseline).'Z';
            }
            $run = [];
        }

        return $d;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
