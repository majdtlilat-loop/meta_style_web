<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use App\View\Charts\Change;
use App\View\Charts\ValueFormat;

/**
 * The insights workspace, ready for Blade: the executive summary, the
 * strongest movements, the business trends and the intelligence sections.
 *
 * Everything is derived from ONE AdvancedWorkspace result (each reader run
 * once per period). A section appears only when the viewer may read it AND
 * it has facts in either period; an all-empty workspace is one empty state.
 */
final class WorkspacePresenter
{
    /** The executive summary, in reading order (the first eight that exist). */
    private const SUMMARY = [
        'billed' => 'receipt',
        'net_collected' => 'wallet',
        'average_ticket' => 'tag',
        'bookings' => 'calendar',
        'arrivals' => 'journey',
        'new_customers' => 'user-plus',
        'return_rate' => 'history',
        'cancellation_rate' => 'x-circle',
        'completed_services' => 'scissors',
        'tickets' => 'ticket',
    ];

    /** Business trends: metric => [section, daily field path]. */
    private const TRENDS = ['bookings', 'arrivals', 'billed', 'net_collected', 'new_customers', 'tickets'];

    public function __construct(
        private readonly AdvancedPeriod $period,
        private readonly string $locale,
    ) {}

    /**
     * @param  array{sections: array<string, array{current: array<string, mixed>, previous: array<string, mixed>}>, focus?: array<string, string>, as_of: string}  $workspace
     * @return array<string, mixed>
     */
    public function present(array $workspace): array
    {
        $sections = $workspace['sections'];
        $current = array_map(static fn (array $section): array => $section['current'], $sections);
        $previous = array_map(static fn (array $section): array => $section['previous'], $sections);

        $currency = Series::leadCurrency([
            (array) ($current['sales']['billed'] ?? []), (array) ($previous['sales']['billed'] ?? []),
            (array) ($current['payments']['collected'] ?? []), (array) ($previous['payments']['collected'] ?? []),
        ]);
        $now = Metrics::values($current, $currency);
        $then = Metrics::values($previous, $currency);
        $buckets = $this->period->buckets($this->locale);
        $previousBuckets = $this->period->comparisonBuckets($this->locale);
        $series = $this->series($current, $currency, $buckets);
        $priorSeries = $this->series($previous, $currency, $previousBuckets);

        $cards = new SectionCards($currency, $this->locale, $buckets, $previousBuckets, $series, $priorSeries);
        $blocks = array_values(array_filter([
            $cards->sales($current, $previous, $now, $then),
            $cards->bookings($current, $previous, $now, $then),
            $cards->customers($current, $previous, $now, $then),
            $cards->services($current, $previous, $now, $then),
            $cards->employees($current, $previous),
            $cards->queue($current, $previous, $now, $then),
            $cards->retention($current, $previous, $now, $then),
        ]));

        $summary = [];
        foreach (self::SUMMARY as $key => $icon) {
            if (count($summary) < 8 && ($card = Metrics::card($key, $now, $then, $currency, $series[$key] ?? [], $icon)) !== null && $this->meaningful($card)) {
                $summary[] = $card;
            }
        }

        $trends = $this->trends($now, $then, $currency, $buckets, $previousBuckets, $series, $priorSeries);

        return [
            'empty' => $blocks === [] && $trends === [] && $this->blank($now, $then),
            // Narrowed to one employee's or one service's work (money, customers,
            // reviews and loyalty are not attributed to either, so not read).
            'focused' => ($workspace['focus'] ?? []) !== [],
            'currency' => $currency,
            'notes' => Series::currencyNotes((array) ($current['sales']['billed'] ?? []), (array) ($current['payments']['net'] ?? []), $currency),
            'kpis' => $summary,
            'movements' => $this->movements($now, $then, $currency),
            'trends' => $trends,
            'sections' => $blocks,
            'comparison' => $this->period->comparisonLabel($this->locale),
        ];
    }

    /**
     * The per-bucket series of every trend metric (current or comparison).
     *
     * @param  array<string, array<string, mixed>>  $facts
     * @param  list<array{key: string, label: string, from: string, to: string}>  $buckets
     * @return array<string, list<int|float>>
     */
    private function series(array $facts, string $currency, array $buckets): array
    {
        $series = [];

        if (isset($facts['bookings'])) {
            $series['bookings'] = Series::onto((array) $facts['bookings']['daily'], (array) $facts['bookings']['hourly'], $buckets);
        }
        if (isset($facts['visits'])) {
            $series['arrivals'] = Series::onto((array) $facts['visits']['daily'], (array) $facts['visits']['hourly'], $buckets);
        }
        if (isset($facts['sales'])) {
            $series['billed'] = Series::onto((array) ($facts['sales']['daily'][$currency] ?? []), (array) ($facts['sales']['hourly'][$currency] ?? []), $buckets);
        }
        if (isset($facts['payments'])) {
            $series['net_collected'] = Series::onto((array) ($facts['payments']['daily'][$currency] ?? []), (array) ($facts['payments']['hourly'][$currency] ?? []), $buckets);
        }
        if (isset($facts['customers'])) {
            $series['new_customers'] = Series::onto((array) ($facts['customers']['daily_new'] ?? []), (array) ($facts['customers']['hourly_new'] ?? []), $buckets);
        }
        if (isset($facts['queue'])) {
            $series['tickets'] = Series::onto(Series::column((array) $facts['queue']['daily'], 'tickets'), (array) ($facts['queue']['hourly'] ?? []), $buckets);
        }

        return $series;
    }

    /**
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @param  list<array{key: string, label: string, from: string, to: string}>  $buckets
     * @param  list<array{key: string, label: string, from: string, to: string}>  $previousBuckets
     * @param  array<string, list<int|float>>  $series
     * @param  array<string, list<int|float>>  $priorSeries
     * @return list<array<string, mixed>>
     */
    private function trends(array $now, array $then, string $currency, array $buckets, array $previousBuckets, array $series, array $priorSeries): array
    {
        $trends = [];

        foreach (self::TRENDS as $key) {
            if (! isset($series[$key]) || (Series::blank($series[$key]) && Series::blank($priorSeries[$key] ?? []))) {
                continue;
            }

            $format = Metrics::format($key);
            $total = $now[$key] ?? array_sum($series[$key]);
            $change = Change::between($total, $then[$key] ?? null, Metrics::higherIsBetter($key));

            $trends[] = [
                'key' => $key,
                'label' => Metrics::label($key),
                'format' => $format,
                'currency' => $format === 'money' ? $currency : null,
                'total' => $total,
                'total_text' => ValueFormat::make($format, $format === 'money' ? $currency : null)->full($total),
                'change' => $change->label(ValueFormat::make($format, $format === 'money' ? $currency : null)),
                'tone' => $change->tone,
                'buckets' => array_map(static fn (array $bucket): array => ['label' => $bucket['label']], $buckets),
                'values' => $series[$key],
                'previous' => [
                    'label' => __('manager_advanced.period.comparison_series'),
                    'values' => $priorSeries[$key] ?? [],
                    'labels' => array_map(static fn (array $bucket): string => $bucket['label'], $previousBuckets),
                ],
            ];
        }

        return $trends;
    }

    /**
     * The strongest judged movements: the largest relative improvements and
     * declines among metrics that have a direction and a non-zero base.
     * Deterministic arithmetic over the figures above — not an AI opinion.
     *
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @return array{up: list<array<string, string>>, down: list<array<string, string>>}
     */
    private function movements(array $now, array $then, string $currency): array
    {
        $moves = [];

        foreach ($now as $key => $value) {
            $higher = Metrics::higherIsBetter($key);
            $before = $then[$key] ?? null;

            if ($higher === null || $value === null || $before === null || (float) $before === 0.0) {
                continue;
            }

            $change = Change::between($value, $before, $higher);

            if ($change->tone === 'neutral' || $change->percent === null) {
                continue;
            }

            $format = Metrics::format($key);
            $fmt = ValueFormat::make($format, $format === 'money' ? $currency : null);
            $moves[] = [
                'tone' => $change->tone,
                'weight' => abs($change->percent),
                'label' => Metrics::label($key),
                'change' => $change->label($fmt),
                'values' => $fmt->full($before).' → '.$fmt->full($value),
            ];
        }

        usort($moves, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $pick = static fn (string $tone): array => array_map(
            static fn (array $move): array => ['label' => $move['label'], 'change' => $move['change'], 'values' => $move['values']],
            array_slice(array_values(array_filter($moves, static fn (array $move): bool => $move['tone'] === $tone)), 0, 3),
        );

        return ['up' => $pick('good'), 'down' => $pick('bad')];
    }

    /** @param array<string, mixed> $card */
    private function meaningful(array $card): bool
    {
        return ($card['current'] !== null && (float) $card['current'] !== 0.0)
            || ($card['previous'] !== null && (float) $card['previous'] !== 0.0);
    }

    /**
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     */
    private function blank(array $now, array $then): bool
    {
        return Series::blank(array_values($now)) && Series::blank(array_values($then));
    }
}
