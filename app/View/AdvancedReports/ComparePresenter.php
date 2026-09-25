<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use App\View\Charts\Change;
use App\View\Charts\ValueFormat;
use App\View\Label;

/**
 * Comparison mode, ready for Blade: the side-by-side KPI table (each entity
 * against the first one picked), grouped bars for shared units, trend
 * overlays and distribution comparisons — for branches, employees, services,
 * or the current period against its comparison period.
 */
final class ComparePresenter
{
    /** The metrics each comparison states, in reading order. */
    public const METRICS = [
        'branches' => ['bookings', 'completion_rate', 'cancellation_rate', 'no_show_rate', 'arrivals', 'completed_visits', 'walk_ins', 'completed_services', 'average_service_minutes', 'billed', 'average_ticket', 'net_collected', 'refunded', 'customers', 'new_customers', 'return_rate', 'average_rating', 'tickets', 'first_call'],
        'employees' => ['bookings', 'completion_rate', 'cancellation_rate', 'no_show_rate', 'arrivals', 'completed_services', 'service_minutes', 'average_service_minutes', 'tickets', 'first_call', 'service_start'],
        'services' => ['bookings', 'completion_rate', 'cancellation_rate', 'no_show_rate', 'arrivals', 'completed_services', 'service_minutes', 'average_service_minutes', 'tickets', 'first_call'],
    ];

    private const TRENDS = ['bookings', 'arrivals', 'billed', 'net_collected', 'tickets'];

    public function __construct(
        private readonly AdvancedPeriod $period,
        private readonly string $locale,
    ) {}

    /**
     * @param  list<array{key: string, name: string|null, facts: array<string, array<string, mixed>>}>  $entities
     * @param  array<string, string>  $names  uuid => display name
     * @return array<string, mixed>
     */
    public function entities(string $mode, array $entities, array $names): array
    {
        if (count($entities) < 2) {
            return ['ready' => false];
        }

        $currency = Series::leadCurrency(array_merge(...array_map(static fn (array $entity): array => [
            (array) ($entity['facts']['sales']['billed'] ?? []), (array) ($entity['facts']['payments']['collected'] ?? []),
        ], $entities)));
        $labels = array_map(static fn (array $entity): string => (string) ($entity['name'] ?? $names[$entity['key']] ?? '—'), $entities);
        $values = array_map(static fn (array $entity): array => Metrics::values($entity['facts'], $currency), $entities);
        $keys = array_values(array_filter(self::METRICS[$mode] ?? [], static fn (string $key): bool => array_key_exists($key, $values[0])));
        $buckets = $this->period->buckets($this->locale);
        $axis = array_map(static fn (array $bucket): array => ['label' => $bucket['label']], $buckets);

        $cards = array_values(array_filter([
            $this->groupedMetrics(__('manager_advanced.compare.volume'), ['bookings', 'arrivals', 'completed_services'], $keys, $labels, $values, 'number', null),
            $this->groupedMetrics(__('manager_advanced.compare.outcomes'), ['completion_rate', 'cancellation_rate', 'no_show_rate'], $keys, $labels, $values, 'percent', null),
            $this->groupedMetrics(__('manager_advanced.compare.money'), ['billed', 'net_collected'], $keys, $labels, $values, 'money', $currency),
            $this->mix($mode, $entities, $labels),
        ]));

        return [
            'ready' => true,
            'currency' => $currency,
            'notes' => Series::currencyNotes(self::money($entities, 'sales', 'billed'), self::money($entities, 'payments', 'net'), $currency),
            'entities' => $labels,
            'table' => $this->table($keys, $labels, $values, $currency),
            'trends' => $this->overlays($entities, $labels, $currency, $buckets, $axis),
            'cards' => $cards,
        ];
    }

    /**
     * The current period against its comparison period, every metric the
     * viewer may read: value, comparison value, difference and change.
     *
     * @param  array{sections: array<string, array{current: array<string, mixed>, previous: array<string, mixed>}>}  $workspace
     * @return array<string, mixed>
     */
    public function periods(array $workspace): array
    {
        $current = array_map(static fn (array $section): array => $section['current'], $workspace['sections']);
        $previous = array_map(static fn (array $section): array => $section['previous'], $workspace['sections']);
        // The same lead currency the Insights view picks for the same facts.
        $currency = Series::leadCurrency([
            (array) ($current['sales']['billed'] ?? []), (array) ($previous['sales']['billed'] ?? []),
            (array) ($current['payments']['collected'] ?? []), (array) ($previous['payments']['collected'] ?? []),
        ]);
        $now = Metrics::values($current, $currency);
        $then = Metrics::values($previous, $currency);
        $rows = [];

        foreach (array_keys(Metrics::DEFINITIONS) as $key) {
            if (! array_key_exists($key, $now) || ($key === 'outstanding')) {
                continue;
            }

            $format = Metrics::format($key);
            $fmt = ValueFormat::make($format, $format === 'money' ? $currency : null);
            $change = $now[$key] === null ? null : Change::between($now[$key], $then[$key] ?? null, Metrics::higherIsBetter($key));
            $rows[] = [
                'label' => Metrics::label($key),
                'section' => (string) __('manager_advanced.sections.'.Metrics::DEFINITIONS[$key][0]),
                'current' => $fmt->full($now[$key]),
                'previous' => $fmt->full($then[$key] ?? null),
                'difference' => $change !== null && $change->difference !== null ? $fmt->difference($change->difference) : '—',
                'change' => $change !== null && $change->label($fmt) !== '' ? $change->label($fmt) : '—',
                'tone' => $change->tone ?? 'neutral',
            ];
        }

        $periods = [(string) __('manager_advanced.period.current_series'), (string) __('manager_advanced.period.comparison_series')];
        $status = ['completed', 'confirmed', 'booked', 'no_show', 'cancelled'];
        $sources = array_keys((array) ($current['bookings']['sources'] ?? []) + (array) ($previous['bookings']['sources'] ?? []));
        $methods = array_keys((array) ($current['payments']['methods'] ?? []) + (array) ($previous['payments']['methods'] ?? []));

        $cards = array_values(array_filter([
            isset($current['bookings']) ? Cards::grouped(__('manager_advanced.cards.booking_status'), array_map(static fn (string $code): string => Label::for('appointment_status', $code), $status), [
                ['label' => $periods[0], 'values' => array_map(static fn (string $code): int => (int) ($current['bookings']['status'][$code] ?? 0), $status)],
                ['label' => $periods[1], 'values' => array_map(static fn (string $code): int => (int) ($previous['bookings']['status'][$code] ?? 0), $status)],
            ]) : null,
            $sources !== [] ? Cards::grouped(__('manager_advanced.cards.booking_sources'), array_map(static fn (int|string $code): string => (string) __('manager_advanced.source.'.$code), $sources), [
                ['label' => $periods[0], 'values' => array_map(static fn (int|string $code): int => (int) ($current['bookings']['sources'][$code] ?? 0), $sources)],
                ['label' => $periods[1], 'values' => array_map(static fn (int|string $code): int => (int) ($previous['bookings']['sources'][$code] ?? 0), $sources)],
            ]) : null,
            $methods !== [] ? Cards::grouped(__('manager_advanced.cards.payment_methods'), array_map(static fn (int|string $method): string => $method === '' ? (string) __('manager_advanced.values.other') : Label::for('pos_payment_method', (string) $method), $methods), [
                ['label' => $periods[0], 'values' => array_map(static fn (int|string $method): int => (int) ($current['payments']['methods'][$method][$currency] ?? 0), $methods)],
                ['label' => $periods[1], 'values' => array_map(static fn (int|string $method): int => (int) ($previous['payments']['methods'][$method][$currency] ?? 0), $methods)],
            ], 'money', $currency) : null,
        ]));

        return [
            'ready' => $rows !== [],
            'currency' => $currency,
            'notes' => Series::currencyNotes((array) ($current['sales']['billed'] ?? []), (array) ($current['payments']['net'] ?? []), $currency),
            'rows' => $rows,
            'cards' => $cards,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $labels
     * @param  list<array<string, int|float|null>>  $values
     * @return list<array<string, mixed>>
     */
    private function table(array $keys, array $labels, array $values, string $currency): array
    {
        $rows = [];

        foreach ($keys as $key) {
            $format = Metrics::format($key);
            $fmt = ValueFormat::make($format, $format === 'money' ? $currency : null);
            $baseline = $values[0][$key] ?? null;
            $cells = [];

            foreach ($values as $i => $entity) {
                $value = $entity[$key] ?? null;
                $change = $i === 0 || $value === null ? null : Change::between($value, $baseline, Metrics::higherIsBetter($key));
                $cells[] = [
                    'value' => $fmt->full($value),
                    'delta' => $change !== null && $change->label($fmt) !== '' ? $change->label($fmt) : null,
                    'tone' => $change->tone ?? 'neutral',
                ];
            }

            $rows[] = ['label' => Metrics::label($key), 'cells' => $cells];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $metrics
     * @param  list<string>  $keys
     * @param  list<string>  $labels
     * @param  list<array<string, int|float|null>>  $values
     * @return array<string, mixed>|null
     */
    private function groupedMetrics(string $title, array $metrics, array $keys, array $labels, array $values, string $format, ?string $currency): ?array
    {
        // A metric one entity cannot have (a rate with no denominator) is not
        // drawn as a 0 bar; the comparison table shows it as "—".
        $metrics = array_values(array_filter(
            array_intersect($metrics, $keys),
            static function (string $key) use ($values): bool {
                foreach ($values as $entity) {
                    if (($entity[$key] ?? null) === null) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return $metrics === [] ? null : Cards::grouped($title, array_map(static fn (string $key): string => Metrics::label($key), $metrics), array_map(static fn (string $label, array $entity): array => [
            'label' => $label,
            'values' => array_map(static fn (string $key): int|float => $entity[$key] ?? 0, $metrics),
        ], $labels, $values), $format, $currency, 'third');
    }

    /**
     * What each entity's delivered work is made of: services per employee,
     * performers per service, services per branch — top five groups overall.
     *
     * @param  list<array{key: string, name: string|null, facts: array<string, array<string, mixed>>}>  $entities
     * @param  list<string>  $labels
     * @return array<string, mixed>|null
     */
    private function mix(string $mode, array $entities, array $labels): ?array
    {
        $dimension = $mode === 'services' ? 'employees' : 'services';
        $totals = [];
        $per = [];

        foreach ($entities as $i => $entity) {
            foreach ((array) ($entity['facts']['visits']['stages'][$dimension] ?? []) as $row) {
                $name = (string) $row['name'];
                $per[$i][$name] = ($per[$i][$name] ?? 0) + (int) $row['completed'];
                $totals[$name] = ($totals[$name] ?? 0) + (int) $row['completed'];
            }
        }

        arsort($totals);
        $groups = array_slice(array_keys($totals), 0, 5);

        return Cards::grouped(__('manager_advanced.compare.mix_'.$dimension), $groups, array_map(static fn (string $label, int $i): array => [
            'label' => $label,
            'values' => array_map(static fn (string $group): int => $per[$i][$group] ?? 0, $groups),
        ], $labels, array_keys($labels)), 'number', null, 'full');
    }

    /**
     * One money map (billed, net collected) of every entity, per currency —
     * only to DISCLOSE the currencies that are not the lead one.
     *
     * @param  list<array{key: string, name: string|null, facts: array<string, array<string, mixed>>}>  $entities
     * @return array<string, int>
     */
    private static function money(array $entities, string $section, string $field): array
    {
        $totals = [];

        foreach ($entities as $entity) {
            foreach ((array) ($entity['facts'][$section][$field] ?? []) as $code => $amount) {
                $totals[(string) $code] = ($totals[(string) $code] ?? 0) + (int) $amount;
            }
        }

        return $totals;
    }

    /**
     * One multiline card per metric with a time series in every entity.
     *
     * @param  list<array{key: string, name: string|null, facts: array<string, array<string, mixed>>}>  $entities
     * @param  list<string>  $labels
     * @param  list<array{key: string, label: string, from: string, to: string}>  $buckets
     * @param  list<array{label: string}>  $axis
     * @return list<array<string, mixed>>
     */
    private function overlays(array $entities, array $labels, string $currency, array $buckets, array $axis): array
    {
        $trends = [];

        foreach (self::TRENDS as $key) {
            $series = [];

            foreach ($entities as $i => $entity) {
                $facts = $entity['facts'];
                $values = match ($key) {
                    'bookings' => isset($facts['bookings']) ? Series::onto((array) $facts['bookings']['daily'], (array) $facts['bookings']['hourly'], $buckets) : null,
                    'arrivals' => isset($facts['visits']) ? Series::onto((array) $facts['visits']['daily'], (array) $facts['visits']['hourly'], $buckets) : null,
                    'billed' => isset($facts['sales']) ? Series::onto((array) ($facts['sales']['daily'][$currency] ?? []), (array) ($facts['sales']['hourly'][$currency] ?? []), $buckets) : null,
                    'net_collected' => isset($facts['payments']) ? Series::onto((array) ($facts['payments']['daily'][$currency] ?? []), (array) ($facts['payments']['hourly'][$currency] ?? []), $buckets) : null,
                    default => isset($facts['queue']) ? Series::onto(Series::column((array) $facts['queue']['daily'], 'tickets'), (array) ($facts['queue']['hourly'] ?? []), $buckets) : null,
                };

                if ($values === null) {
                    continue 2;
                }

                $series[] = ['label' => $labels[$i], 'values' => $values, 'color' => $i + 1];
            }

            if (Series::blank(array_merge(...array_map(static fn (array $s): array => $s['values'], $series)))) {
                continue;
            }

            $format = Metrics::format($key);
            $trends[] = ['key' => $key, 'label' => Metrics::label($key), 'buckets' => $axis, 'series' => $series, 'format' => $format, 'currency' => $format === 'money' ? $currency : null];
        }

        return $trends;
    }
}
