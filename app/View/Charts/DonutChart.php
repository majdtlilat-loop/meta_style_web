<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * View model for <x-chart.donut>: part-to-whole at a glance. At most `$keep`
 * named slices (default 7) and the rest folded into "Other"; a 2px surface
 * gap between slices; the total in the centre; a legend with value and share.
 *
 *   mode 'categorical'  slots 1…8 in order (or an item's `color`)
 *   mode 'status'       each item's `status` code (or `tone`) → the reserved
 *                       status palette, in the validated good → critical order
 */
final class DonutChart
{
    /**
     * @param  array<mixed>  $items  (untyped Blade input) each ['label' => string, 'value' => int|float, 'status' => ?string, 'tone' => ?string, 'color' => int|string|null]
     * @return array<string, mixed>
     */
    public static function build(string $label, array $items, string $mode, ?string $format, ?string $currency, ?bool $sort, int $keep = 7, ?string $total = null, ?string $centerLabel = null): array
    {
        $fmt = ValueFormat::make($format, $currency);
        $status = $mode === 'status';
        $otherLabel = (string) __('manager_charts.other');

        $clean = [];
        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $tone = $status ? Palette::statusTone(ChartData::text($item['tone'] ?? null) ?: ChartData::text($item['status'] ?? null)) : null;
            $clean[] = [
                'label' => ChartData::text($item['label'] ?? null),
                'value' => ChartData::magnitude($item['value'] ?? 0),
                'tone' => $tone,
                'color' => $item['color'] ?? null,
                'order' => $index,
            ];
        }

        if ($status && $sort !== true) {
            usort($clean, static fn (array $a, array $b): int => [Palette::statusRank((string) $a['tone']), $a['order']] <=> [Palette::statusRank((string) $b['tone']), $b['order']]);
        }

        $folded = Fold::top($clean, max(1, min($keep, Palette::SLOTS - 1)), $otherLabel, $status ? $sort === true : $sort !== false);
        $statusIds = $status ? Palette::statusSeries(array_map(static fn (array $s): string => (string) ($s['tone'] ?? 'neutral'), $folded)) : [];

        $sum = array_sum(array_map(static fn (array $s): float => (float) $s['value'], $folded));
        $geometry = Arc::donut(array_map(static fn (array $s): int|float => $s['value'] ?? 0, $folded));

        $slices = [];
        foreach ($folded as $i => $slice) {
            $series = match (true) {
                (bool) $slice['other'] => 'other',
                $status => $statusIds[$i],
                default => Palette::resolve($slice['color'] ?? null, Palette::slot($i)),
            };
            $share = $sum > 0 ? (float) $slice['value'] / $sum * 100 : 0.0;
            $value = $fmt->full($slice['value']);
            $shareText = self::share($share);
            $members = [];
            foreach ((array) ($slice['members'] ?? []) as $member) {
                $members[] = [
                    'label' => ChartData::text($member['label'] ?? null),
                    'value' => $fmt->full(ChartData::magnitude($member['value'] ?? 0)),
                    'share' => self::share($sum > 0 ? (float) ChartData::magnitude($member['value'] ?? 0) / $sum * 100 : 0.0),
                ];
            }

            $rows = [ChartData::row($series, (string) $slice['label'], $value), ChartData::row('total', (string) __('manager_charts.share'), $shareText)];
            if ($members !== []) {
                $rows[] = ChartData::row('total', trans_choice('manager_charts.folded', count($members), ['count' => count($members)]), '');
            }

            $slices[] = [
                'index' => $i + 1,
                'label' => (string) $slice['label'],
                'value' => $value,
                'share' => $shareText,
                'series' => $series,
                'd' => $geometry[$i]['d'] ?? null,
                'rows' => $rows,
                'members' => $members,
                'other' => (bool) $slice['other'],
            ];
        }

        $empty = $sum <= 0;
        $largest = null;
        foreach ($slices as $i => $slice) {
            if (! $slice['other'] && ($largest === null || (float) $folded[$i]['value'] > (float) $folded[$largest]['value'])) {
                $largest = $i;
            }
        }

        return [
            'id' => ChartData::id('donut'),
            'empty' => $empty,
            'slices' => $slices,
            'total' => $total ?? $fmt->short($sum),
            'total_full' => $fmt->full($sum),
            'center_label' => $centerLabel ?? (string) __('manager_charts.total'),
            'summary' => $empty ? '' : (string) __('manager_charts.summary.parts', [
                'label' => $label,
                'count' => count($clean),
                'total' => $fmt->full($sum),
                'top' => $largest === null ? '' : $slices[$largest]['label'],
                'share' => $largest === null ? '' : $slices[$largest]['share'],
            ]),
        ];
    }

    public static function share(float $percent): string
    {
        if ($percent > 0 && $percent < 1) {
            return '<1%';
        }

        return number_format($percent, $percent >= 10 || floor($percent) === $percent ? 0 : 1).'%';
    }
}
