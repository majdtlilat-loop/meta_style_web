<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.heatmap>: a rows × columns matrix (day of week ×
 * hour) on the one-hue sequential ramp. Five steps, linear from zero to the
 * maximum; an exact zero or a missing cell is "empty", never the lightest
 * step, so "none" and "a few" never look alike.
 */
final class Heatmap
{
    public const STEPS = 5;

    /**
     * @param  array<mixed>  $rows  strings or ['label' => string]
     * @param  array<mixed>  $columns  strings or ['label' => string]
     * @param  array<mixed>  $values  one list per row, one value per column (untyped Blade input)
     * @return array<string, mixed>
     */
    public static function build(string $label, array $rows, array $columns, array $values, ?string $format, ?string $currency, ?string $rowHeader = null, ?string $columnHeader = null): array
    {
        $fmt = ValueFormat::make($format, $currency);
        $rowLabels = Axis::labels($rows);
        $columnLabels = Axis::labels($columns);
        $width = count($columnLabels);

        $matrix = [];
        foreach ($rowLabels as $r => $rowLabel) {
            $matrix[] = ChartData::values(array_values($values)[$r] ?? [], $width);
        }

        $known = [];
        foreach ($matrix as $line) {
            foreach ($line as $value) {
                if ($value !== null) {
                    $known[] = (float) $value;
                }
            }
        }
        $max = $known === [] ? 0.0 : max(0.0, ...$known);
        $empty = $width === 0 || $rowLabels === [] || $max <= 0;

        $cells = [];
        $peak = null;
        foreach ($matrix as $r => $line) {
            foreach ($line as $c => $value) {
                $step = self::step($value, $max);
                $text = $fmt->full($value ?? 0);
                $cells[] = [
                    'step' => $step,
                    'title' => $rowLabels[$r].' · '.$columnLabels[$c],
                    'value' => $text,
                    'rows' => [ChartData::row('seq-'.$step, $label, $text)],
                    'aria' => $rowLabels[$r].' '.$columnLabels[$c].': '.$text,
                ];
                if ($value !== null && ($peak === null || $value > $peak[0])) {
                    $peak = [$value, $rowLabels[$r].' · '.$columnLabels[$c]];
                }
            }
        }

        $legend = [];
        for ($s = 1; $s <= self::STEPS; $s++) {
            $legend[] = [
                'step' => $s,
                'range' => $fmt->full($max * ($s - 1) / self::STEPS).' – '.$fmt->full($max * $s / self::STEPS),
            ];
        }

        return [
            'id' => ChartData::id('heat'),
            'empty' => $empty,
            'rows' => $rowLabels,
            'columns' => Axis::ticks($columnLabels, 12),
            'column_labels' => $columnLabels,
            'width' => $width,
            'cells' => $cells,
            'table' => array_map(static fn (string $rowLabel, int $r): array => [
                'label' => $rowLabel,
                'values' => array_map(static fn ($value): string => $fmt->full($value ?? 0), $matrix[$r]),
            ], $rowLabels, array_keys($rowLabels)),
            'legend' => $legend,
            'min_label' => $fmt->full(0),
            'max_label' => $fmt->full($max),
            'row_header' => $rowHeader ?? '',
            'column_header' => $columnHeader ?? '',
            'summary' => $empty ? '' : (string) __('manager_charts.summary.matrix', [
                'label' => $label,
                'high' => $fmt->full($peak[0] ?? 0),
                'at' => $peak[1] ?? '',
            ]),
        ];
    }

    /** 0 for none; 1–5 for (0, max] in equal steps. */
    public static function step(int|float|null $value, float $max): int
    {
        if ($value === null || $value <= 0 || $max <= 0) {
            return 0;
        }

        return max(1, min(self::STEPS, (int) ceil((float) $value / $max * self::STEPS)));
    }
}
