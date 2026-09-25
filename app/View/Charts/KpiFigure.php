<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.kpi>: the value, the previous value and the change
 * between them, toned by what the METRIC counts as better.
 *
 *   higher_is_better  true   bookings, revenue, completion rate
 *                     false  cancellation rate, no-shows, average wait
 *                     null   neutral (a mix, a count with no direction)
 */
final class KpiFigure
{
    /**
     * @return array<string, mixed>
     */
    public static function build(int|float|null $current, int|float|null $previous, ?string $format, ?string $currency, ?bool $higherIsBetter, ?string $value, ?string $comparison): array
    {
        $fmt = ValueFormat::make($format, $currency);
        $change = $current === null ? null : Change::between($current, $previous, $higherIsBetter);
        $previousText = $previous === null ? null : $fmt->full($previous);

        $compare = null;
        if ($previousText !== null) {
            $compare = $comparison !== null && $comparison !== ''
                ? (string) __('manager_charts.kpi.versus_period', ['period' => $comparison, 'value' => $previousText])
                : (string) __('manager_charts.kpi.versus', ['value' => $previousText]);
        }

        return [
            'value' => $value ?? $fmt->full($current),
            'delta' => $change !== null && $change->direction !== 'none' ? [
                'text' => $change->label($fmt),
                'tone' => $change->tone,
                'direction' => $change->direction,
                'sr' => $change->toneLabel(),
            ] : null,
            'compare' => $compare,
            // The absolute difference, only where the delta shows a relative
            // percentage (a zero base or a percent metric already prints the
            // difference itself, and "No change" needs no "+0").
            'difference' => $change !== null && $change->percent !== null && $change->direction !== 'flat' && $fmt->kind !== 'percent'
                ? $fmt->difference((float) $change->difference)
                : null,
        ];
    }
}
