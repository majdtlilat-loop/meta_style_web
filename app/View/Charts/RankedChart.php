<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.ranked>: named categories compared by magnitude,
 * largest first, the value printed at the bar's end, an optional tick where
 * the previous period stood, and the tail past `$limit` folded into "Other".
 *
 * One hue for every bar (slot 1) — the rows are labelled, so colour never
 * re-encodes what the length already shows; Other wears the muted role.
 */
final class RankedChart
{
    /**
     * @param  array<mixed>  $items  (untyped Blade input) each ['label' => string, 'value' => int|float, 'previous' => int|float|null, 'href' => ?string, 'meta' => ?string]
     * @return array<string, mixed>
     */
    public static function build(string $label, array $items, ?string $format, ?string $currency, int $limit = 7, bool $share = false, bool $sort = true, ?bool $higherIsBetter = true): array
    {
        $fmt = ValueFormat::make($format, $currency);

        $clean = [];
        $hasPrevious = false;
        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = [
                'label' => ChartData::text($item['label'] ?? null),
                'value' => ChartData::magnitude($item['value'] ?? 0),
                'href' => ChartData::text($item['href'] ?? null) ?: null,
                'meta' => ChartData::text($item['meta'] ?? null) ?: null,
            ];
            if (array_key_exists('previous', $item) && ChartData::number($item['previous']) !== null) {
                $row['previous'] = ChartData::magnitude($item['previous']);
                $hasPrevious = true;
            }
            $clean[] = $row;
        }

        $folded = Fold::top($clean, max(1, $limit), (string) __('manager_charts.other'), $sort);
        $values = array_map(static fn (array $row): float => (float) $row['value'], $folded);
        $previousValues = array_map(static fn (array $row): float => (float) ($row['previous'] ?? 0), $folded);
        $max = max([0.0, ...$values, ...$previousValues]);
        $total = array_sum(array_map(static fn (array $row): float => (float) $row['value'], $clean));
        $previousWord = (string) __('manager_charts.previous');

        $rows = [];
        foreach ($folded as $row) {
            $value = $row['value'];
            $previous = array_key_exists('previous', $row) ? $row['previous'] : null;
            $shareText = $total > 0 ? DonutChart::share((float) $value / $total * 100) : '0%';
            $tip = [ChartData::row('1', (string) $row['label'], $fmt->full($value))];
            $change = null;
            if ($previous !== null) {
                $tip[] = ChartData::row('previous', $previousWord, $fmt->full($previous));
                $delta = Change::between($value, $previous, $higherIsBetter);
                $change = $delta->label($fmt);
                if ($change !== '') {
                    $tip[] = ChartData::row('total', (string) __('manager_charts.change'), $change);
                }
            }
            if ($share) {
                $tip[] = ChartData::row('total', (string) __('manager_charts.share'), $shareText);
            }

            $members = [];
            foreach ((array) ($row['members'] ?? []) as $member) {
                $members[] = [
                    'label' => ChartData::text($member['label'] ?? null),
                    'value' => $fmt->full(ChartData::magnitude($member['value'] ?? 0)),
                    'previous' => array_key_exists('previous', (array) $member) ? $fmt->full(ChartData::magnitude($member['previous'])) : null,
                    'share' => $total > 0 ? DonutChart::share((float) ChartData::magnitude($member['value'] ?? 0) / $total * 100) : '0%',
                ];
            }

            $rows[] = [
                'label' => (string) $row['label'],
                'href' => $row['href'] ?? null,
                'meta' => $row['meta'] ?? null,
                'value' => $fmt->full($value),
                'share' => $shareText,
                'width' => $max > 0 ? round((float) $value / $max * 100, 3) : 0.0,
                'previous' => $previous === null ? null : $fmt->full($previous),
                'previous_pos' => $previous === null || $max <= 0 ? null : round((float) $previous / $max * 100, 3),
                'change' => $change,
                'series' => $row['other'] ? 'other' : '1',
                'rows' => $tip,
                'members' => $members,
                'other' => (bool) $row['other'],
            ];
        }

        $empty = $clean === [] || $total <= 0;

        return [
            'id' => ChartData::id('ranked'),
            'empty' => $empty,
            'rows' => $rows,
            'has_previous' => $hasPrevious,
            'total' => ChartData::additive($fmt) ? $fmt->full($total) : null,
            'summary' => $empty ? '' : (string) __('manager_charts.summary.ranked', [
                'label' => $label,
                'count' => count($clean),
                'top' => $rows[0]['label'] ?? '',
                'value' => $rows[0]['value'] ?? '',
            ]),
        ];
    }
}
