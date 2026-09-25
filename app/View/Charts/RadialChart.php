<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.radial>: one ratio against its maximum (a
 * completion rate, a repeat rate, a quota), a ring whose unfilled track is a
 * lighter step of the fill, with an optional target tick and previous dot.
 */
final class RadialChart
{
    public const RADIUS = 42.0;

    /**
     * @return array<string, mixed>
     */
    public static function build(string $label, int|float|null $value, int|float $max, ?string $format, ?string $currency, int|float|null $target, int|float|null $previous, string $tone, ?string $display): array
    {
        $fmt = ValueFormat::make($format ?? 'percent', $currency);
        $max = $max > 0 ? $max : 100;
        $fraction = $value === null ? 0.0 : max(0.0, min(1.0, (float) $value / $max));
        $series = match ($tone) {
            'good', 'warning', 'critical', 'info', 'neutral' => $tone,
            default => '1',
        };

        $targetFraction = $target === null ? null : max(0.0, min(1.0, (float) $target / $max));
        $previousFraction = $previous === null ? null : max(0.0, min(1.0, (float) $previous / $max));

        $outOfHundred = $fmt->kind === 'percent' && (float) $max === 100.0;
        $summary = $outOfHundred
            ? (string) __('manager_charts.summary.single', ['label' => $label, 'value' => $fmt->full($value)])
            : (string) __('manager_charts.summary.progress', ['label' => $label, 'value' => $fmt->full($value), 'max' => $fmt->full($max)]);
        if ($target !== null) {
            $summary .= ' '.__('manager_charts.summary.target', ['value' => $fmt->full($target)]);
        }
        if ($previous !== null) {
            $summary .= ' '.__('manager_charts.summary.previous', ['value' => $fmt->full($previous)]);
        }

        $rows = [['label' => (string) __('manager_charts.value'), 'value' => $fmt->full($value)]];
        if (! $outOfHundred) {
            $rows[] = ['label' => (string) __('manager_charts.maximum'), 'value' => $fmt->full($max)];
        }
        if ($target !== null) {
            $rows[] = ['label' => (string) __('manager_charts.target'), 'value' => $fmt->full($target)];
        }
        if ($previous !== null) {
            $rows[] = ['label' => (string) __('manager_charts.previous'), 'value' => $fmt->full($previous)];
        }

        return [
            'id' => ChartData::id('radial'),
            'empty' => $value === null,
            'series' => $series,
            'dash' => round($fraction * 100, 3),
            'display' => $display ?? $fmt->full($value),
            'target' => $targetFraction === null ? null : Arc::tick($targetFraction, self::RADIUS - 7, self::RADIUS + 7),
            'previous' => $previousFraction === null ? null : explode(' ', Arc::point($previousFraction, self::RADIUS)),
            'target_text' => $target === null ? null : $fmt->full($target),
            'previous_text' => $previous === null ? null : $fmt->full($previous),
            'rows' => $rows,
            'summary' => $summary,
        ];
    }
}
