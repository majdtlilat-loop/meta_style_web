<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use App\View\Charts\Change;
use App\View\Charts\ValueFormat;
use App\View\Label;
use Illuminate\Support\Facades\Lang;

/**
 * The intelligence sections of the workspace, each a titled group of cards.
 * A section returns null when its facts are empty in BOTH periods, so the
 * page never draws a wall of zero charts.
 *
 * `$now` / `$then` are Metrics::values() of the two periods; `$current` /
 * `$previous` the raw section facts they came from.
 */
final class SectionCards
{
    /**
     * @param  list<array{key: string, label: string, from: string, to: string}>  $buckets
     * @param  list<array{key: string, label: string, from: string, to: string}>  $previousBuckets
     * @param  array<string, list<int|float>>  $series
     * @param  array<string, list<int|float>>  $priorSeries
     */
    public function __construct(
        private readonly string $currency,
        private readonly string $locale,
        private readonly array $buckets,
        private readonly array $previousBuckets,
        private readonly array $series,
        private readonly array $priorSeries,
    ) {}

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @return array<string, mixed>|null
     */
    public function sales(array $current, array $previous, array $now, array $then): ?array
    {
        $activity = array_filter(['invoices', 'billed', 'net_collected', 'refunded', 'outstanding'], static fn (string $key): bool => (float) ($now[$key] ?? 0) !== 0.0 || (float) ($then[$key] ?? 0) !== 0.0);

        if ($activity === []) {
            return null;
        }

        $cards = [];
        $billed = $this->series['billed'] ?? null;
        $net = $this->series['net_collected'] ?? null;

        if ($billed !== null && $net !== null) {
            $cards[] = Cards::columns(__('manager_advanced.cards.billed_vs_collected'), $this->axis(), [Metrics::label('billed'), Metrics::label('net_collected')], [$billed, $net], 'money', $this->currency);
        } elseif (($only = $billed ?? $net) !== null) {
            $key = $billed !== null ? 'billed' : 'net_collected';
            $cards[] = Cards::line(Metrics::label($key), $this->axis(), $only, $this->priorSeries[$key] ?? [], $this->previousAxis(), 'money', $this->currency);
        }

        $cards[] = Cards::stats(__('manager_advanced.cards.billing_health'), [
            Metrics::card('invoices', $now, $then, $this->currency),
            Metrics::card('average_ticket', $now, $then, $this->currency),
            Metrics::card('voided', $now, $then, $this->currency),
            Metrics::card('refunded', $now, $then, $this->currency),
            Metrics::card('outstanding', $now, $then, $this->currency),
        ]);

        $methods = [];
        foreach ((array) ($current['payments']['methods'] ?? []) as $method => $amounts) {
            $methods[] = ['label' => $method === '' ? __('manager_advanced.values.other') : Label::for('pos_payment_method', (string) $method), 'value' => (int) ($amounts[$this->currency] ?? 0)];
        }
        $cards[] = Cards::donut(__('manager_advanced.cards.payment_methods'), $methods, 'categorical', 'money', $this->currency);

        $categories = [];
        foreach ((array) ($current['sales']['categories'] ?? []) as $row) {
            if (($row['currency'] ?? null) !== $this->currency) {
                continue;
            }
            $label = $row['category'] ?? __('manager_advanced.kind.'.($row['kind'] ?? 'custom'));
            $categories[$label] = ($categories[$label] ?? 0) + (int) $row['total_minor'];
        }
        $cards[] = Cards::donut(__('manager_advanced.cards.category_mix'), array_map(static fn (string $label, int $value): array => ['label' => $label, 'value' => $value], array_keys($categories), array_values($categories)), 'categorical', 'money', $this->currency);

        $before = [];
        foreach ((array) ($previous['sales']['items'] ?? []) as $item) {
            if (($item['currency'] ?? null) === $this->currency) {
                $before[$item['kind'].'|'.$item['name']] = (int) $item['total_minor'];
            }
        }
        $items = [];
        foreach ((array) ($current['sales']['items'] ?? []) as $item) {
            if (($item['currency'] ?? null) === $this->currency) {
                $items[] = [
                    'label' => (string) $item['name'],
                    'value' => (int) $item['total_minor'],
                    'previous' => $before[$item['kind'].'|'.$item['name']] ?? 0,
                    'meta' => __('manager_advanced.cards.lines', ['count' => number_format((int) $item['count'])]),
                ];
            }
        }
        $cards[] = Cards::ranked(__('manager_advanced.cards.top_items'), $items, 'money', $this->currency, false, true, 'third');

        return $this->block('sales', 'sales', $cards, Series::currencyNotes((array) ($current['sales']['billed'] ?? []), (array) ($current['payments']['net'] ?? []), $this->currency));
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @return array<string, mixed>|null
     */
    public function bookings(array $current, array $previous, array $now, array $then): ?array
    {
        if (! isset($current['bookings']) || ((int) $current['bookings']['total'] === 0 && (int) ($previous['bookings']['total'] ?? 0) === 0)) {
            return null;
        }

        $status = (array) $current['bookings']['status'];
        $codes = ['completed', 'confirmed', 'booked', 'no_show', 'cancelled'];
        $cards = [];

        // Bookings by status over time: the reader's own branch-local
        // per-day / per-hour status counts laid onto the period's buckets.
        $dailyStatus = (array) ($current['bookings']['daily_status'] ?? []);
        $hourlyStatus = (array) ($current['bookings']['hourly_status'] ?? []);
        $cards[] = Cards::stacked(__('manager_advanced.cards.status_over_time'), $this->axis(), array_map(fn (string $code): array => [
            'label' => Label::for('appointment_status', $code),
            'status' => $code,
            'values' => Series::onto(
                array_map(static fn (mixed $counts): int => (int) (((array) $counts)[$code] ?? 0), $dailyStatus),
                array_map(static fn (mixed $counts): int => (int) (((array) $counts)[$code] ?? 0), $hourlyStatus),
                $this->buckets,
            ),
        ], $codes), 'status');

        $cards[] = Cards::donut(__('manager_advanced.cards.booking_status'), array_map(static fn (string $code): array => [
            'label' => Label::for('appointment_status', $code),
            'value' => (int) ($status[$code] ?? 0),
            'status' => $code,
        ], $codes), 'status');

        $cards[] = Cards::radials(__('manager_advanced.cards.booking_outcomes'), [
            Cards::radial(Metrics::label('completion_rate'), $this->float($now['completion_rate'] ?? null), $this->float($then['completion_rate'] ?? null), true, 'good'),
            Cards::radial(Metrics::label('cancellation_rate'), $this->float($now['cancellation_rate'] ?? null), $this->float($then['cancellation_rate'] ?? null), false, 'warning'),
            Cards::radial(Metrics::label('no_show_rate'), $this->float($now['no_show_rate'] ?? null), $this->float($then['no_show_rate'] ?? null), false, 'critical'),
        ]);

        $sources = [];
        foreach ((array) $current['bookings']['sources'] + array_fill_keys(array_keys((array) ($previous['bookings']['sources'] ?? [])), 0) as $code => $count) {
            $sources[] = ['label' => __('manager_advanced.source.'.$code), 'value' => (int) $count, 'previous' => (int) ($previous['bookings']['sources'][$code] ?? 0)];
        }
        $cards[] = Cards::ranked(__('manager_advanced.cards.booking_sources'), $sources, 'number', null, true, true, 'third');
        // The RESERVED lines (what was booked, and with whom) — demand, not
        // delivery: the Employee section credits the actual performer.
        $cards[] = Cards::ranked(__('manager_advanced.cards.booked_services'), $this->against((array) ($current['bookings']['services'] ?? []), (array) ($previous['bookings']['services'] ?? []), 'total'), 'number', null, false, true, 'third');
        $cards[] = Cards::ranked(__('manager_advanced.cards.booked_employees'), $this->against((array) ($current['bookings']['employees'] ?? []), (array) ($previous['bookings']['employees'] ?? []), 'total'), 'number', null, false, true, 'third');
        $cards[] = Cards::heatmap(__('manager_advanced.cards.busy_hours'), Series::heatmap((array) $current['bookings']['hourly'], $this->locale), 'two-thirds');

        return $this->block('bookings', 'calendar', $cards);
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @return array<string, mixed>|null
     */
    public function customers(array $current, array $previous, array $now, array $then): ?array
    {
        $cards = [];

        if (isset($current['customers']) && ((int) $current['customers']['customers'] > 0 || (int) ($previous['customers']['customers'] ?? 0) > 0)) {
            $cards[] = Cards::line(Metrics::label('new_customers'), $this->axis(), $this->series['new_customers'] ?? [], $this->priorSeries['new_customers'] ?? [], $this->previousAxis());
            $cards[] = Cards::stats(__('manager_advanced.cards.customer_base'), [
                Metrics::card('customers', $now, $then, $this->currency),
                Metrics::card('returning_customers', $now, $then, $this->currency),
                Metrics::card('visit_frequency', $now, $then, $this->currency),
            ]);
            $cards[] = Cards::donut(__('manager_advanced.cards.customer_mix'), [
                ['label' => Metrics::label('new_customers'), 'value' => (int) ($now['new_customers'] ?? 0), 'color' => 1],
                ['label' => Metrics::label('returning_customers'), 'value' => (int) ($now['returning_customers'] ?? 0), 'color' => 2],
            ], 'categorical', 'number', null, 6, 'third');

            // How often each customer came: 1, 2, 3, 4, 5 or more visits.
            $visits = [1, 2, 3, 4, 5];
            $cards[] = Cards::grouped(__('manager_advanced.cards.visit_frequency'), array_map(static fn (int $count): string => $count === 5
                ? (string) __('manager_advanced.values.visits_or_more', ['count' => $count])
                : trans_choice('manager_advanced.values.visits', $count, ['count' => $count]), $visits), [
                    ['label' => __('manager_advanced.period.current_series'), 'values' => array_map(static fn (int $count): int => (int) ($current['customers']['frequency'][$count] ?? 0), $visits)],
                    ['label' => __('manager_advanced.period.comparison_series'), 'values' => array_map(static fn (int $count): int => (int) ($previous['customers']['frequency'][$count] ?? 0), $visits)],
                ], 'number', null, 'third');
        }

        $values = array_values(array_filter((array) ($current['payments']['customer_values'] ?? []), fn (array $row): bool => ($row['currency'] ?? null) === $this->currency && (int) $row['net_minor'] > 0));
        if (isset($current['customers']) && $values !== []) {
            $cards[] = Cards::ranked(__('manager_advanced.cards.top_customers'), array_map(static fn (array $row): array => [
                'label' => __('manager_advanced.values.customer_rank', ['rank' => $row['rank']]),
                'value' => (int) $row['net_minor'],
            ], $values), 'money', $this->currency, true, true, 'third');
        }

        if (isset($current['reviews']) && ((int) $current['reviews']['count'] > 0 || (int) ($previous['reviews']['count'] ?? 0) > 0)) {
            $average = $this->float($now['average_rating'] ?? null);
            $cards[] = Cards::radials(__('manager_advanced.cards.satisfaction'), [
                Cards::radial(Metrics::label('average_rating'), $average, $this->float($then['average_rating'] ?? null), true, 'good', 5, 'number', $average === null ? null : number_format($average, 1).' / 5'),
            ]);
            $cards[] = Cards::ranked(__('manager_advanced.cards.rating_distribution'), array_map(static fn (int $stars): array => [
                'label' => trans_choice('manager_advanced.values.stars', $stars, ['count' => $stars]),
                'value' => (int) ($current['reviews']['distribution'][$stars] ?? 0),
                'previous' => (int) ($previous['reviews']['distribution'][$stars] ?? 0),
            ], [5, 4, 3, 2, 1]), 'number', null, true, null, 'third', 5, false);
        }

        return $this->block('customers', 'customers', $cards);
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @return array<string, mixed>|null
     */
    public function services(array $current, array $previous, array $now, array $then): ?array
    {
        if (! isset($current['visits']) || ((int) $current['visits']['total'] === 0 && (int) ($previous['visits']['total'] ?? 0) === 0)) {
            return null;
        }

        $services = (array) ($current['visits']['stages']['services'] ?? []);
        $cards = [];
        $cards[] = Cards::ranked(__('manager_advanced.cards.top_services'), $this->against($services, (array) ($previous['visits']['stages']['services'] ?? []), 'completed'), 'number');
        $cards[] = Cards::ranked(__('manager_advanced.cards.service_time'), array_map(static fn (array $row): array => [
            'label' => (string) $row['name'],
            'value' => (int) $row['completed'] > 0 ? round((int) $row['minutes'] / (int) $row['completed'], 1) : 0,
            'meta' => __('manager_advanced.cards.performed', ['count' => number_format((int) $row['completed'])]),
        ], array_values($services)), 'duration', null, false, null);

        $arrivals = (int) ($now['arrivals'] ?? 0);
        $priorArrivals = (int) ($then['arrivals'] ?? 0);
        $cards[] = Cards::radials(__('manager_advanced.cards.visit_mix'), [
            Cards::radial(Metrics::label('visit_completion'), Series::rate((int) ($now['completed_visits'] ?? 0), $arrivals), Series::rate((int) ($then['completed_visits'] ?? 0), $priorArrivals), true, 'good'),
            Cards::radial(Metrics::label('walk_in_share'), Series::rate((int) ($now['walk_ins'] ?? 0), $arrivals), Series::rate((int) ($then['walk_ins'] ?? 0), $priorArrivals), null),
        ]);
        $cards[] = Cards::heatmap(__('manager_advanced.cards.arrival_hours'), Series::heatmap((array) $current['visits']['hourly'], $this->locale), 'two-thirds');
        $cards[] = Cards::stats(__('manager_advanced.cards.delivery'), [
            Metrics::card('completed_services', $now, $then, $this->currency),
            Metrics::card('service_minutes', $now, $then, $this->currency),
            Metrics::card('aborted_visits', $now, $then, $this->currency),
        ]);

        return $this->block('services', 'scissors', $cards);
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $previous
     * @return array<string, mixed>|null
     */
    public function employees(array $current, array $previous): ?array
    {
        // Keyed by employee id (never by name: two people may share one).
        $employees = (array) ($current['visits']['stages']['employees'] ?? []);
        $prior = (array) ($previous['visits']['stages']['employees'] ?? []);

        if ($employees === [] && $prior === []) {
            return null;
        }

        $duration = ValueFormat::make('duration');
        $count = ValueFormat::make();
        uasort($employees, static fn (array $a, array $b): int => (int) $b['completed'] <=> (int) $a['completed']);

        $cards = [];
        $cards[] = Cards::ranked(__('manager_advanced.cards.employee_services'), $this->against($employees, $prior, 'completed'), 'number');
        $cards[] = Cards::donut(__('manager_advanced.cards.employee_share'), array_values(array_map(static fn (array $row): array => [
            'label' => (string) $row['name'],
            'value' => (int) $row['completed'],
        ], $employees)), 'categorical', 'number', null, 6, 'half');

        $rows = [];
        $sort = [];
        foreach ($employees as $id => $row) {
            $completed = (int) $row['completed'];
            $before = isset($prior[$id]) ? (int) $prior[$id]['completed'] : null;
            $change = Change::between($completed, $before, true);
            $average = $completed > 0 ? round((int) $row['minutes'] / $completed, 1) : null;
            $rows[] = [
                (string) $row['name'],
                $count->full($completed),
                $count->full($before),
                $change->label($count) === '' ? '—' : $change->label($count),
                $duration->full((int) $row['minutes']),
                $duration->full($average),
            ];
            $sort[] = [(string) $row['name'], $completed, $before, $change->percent, (int) $row['minutes'], $average];
        }

        $cards[] = Cards::table(__('manager_advanced.cards.employee_table'), [
            ['label' => __('manager_advanced.columns.employee'), 'numeric' => false],
            ['label' => __('manager_advanced.columns.completed'), 'numeric' => true],
            ['label' => __('manager_advanced.columns.previous'), 'numeric' => true],
            ['label' => __('manager_advanced.columns.change'), 'numeric' => true],
            ['label' => __('manager_advanced.columns.minutes'), 'numeric' => true],
            ['label' => __('manager_advanced.columns.average_time'), 'numeric' => true],
        ], $rows, 'full', $sort);

        return $this->block('employees', 'staff', $cards);
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @return array<string, mixed>|null
     */
    public function queue(array $current, array $previous, array $now, array $then): ?array
    {
        if (! isset($current['queue']) || ((int) $current['queue']['total'] === 0 && (int) ($previous['queue']['total'] ?? 0) === 0)) {
            return null;
        }

        $sources = (array) ($current['queue']['sources'] ?? []);
        $states = (array) ($current['queue']['states'] ?? []);

        return $this->block('queue', 'queue', [
            Cards::line(Metrics::label('tickets'), $this->axis(), $this->series['tickets'] ?? [], $this->priorSeries['tickets'] ?? [], $this->previousAxis(), 'number', null, 'full'),
            Cards::stats(__('manager_advanced.cards.waiting'), [
                Metrics::card('tickets', $now, $then, $this->currency),
                Metrics::card('first_call', $now, $then, $this->currency),
                Metrics::card('service_start', $now, $then, $this->currency),
            ]),
            Cards::donut(__('manager_advanced.cards.ticket_sources'), array_map(static fn (int|string $source, mixed $count): array => [
                'label' => Lang::has('manager_advanced.queue_source.'.$source) ? (string) __('manager_advanced.queue_source.'.$source) : (string) __('manager_advanced.values.other'),
                'value' => (int) $count,
            ], array_keys($sources), array_values($sources))),
            Cards::donut(__('manager_advanced.cards.ticket_outcomes'), array_map(static fn (int|string $state, mixed $count): array => [
                'label' => Label::for('ticket_state', (string) $state),
                'value' => (int) $count,
                'status' => (string) $state,
            ], array_keys($states), array_values($states)), 'status'),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, array<string, mixed>>  $previous
     * @param  array<string, int|float|null>  $now
     * @param  array<string, int|float|null>  $then
     * @return array<string, mixed>|null
     */
    public function retention(array $current, array $previous, array $now, array $then): ?array
    {
        $cards = [];
        $cards[] = Cards::radials(__('manager_advanced.cards.return_rate'), [
            Cards::radial(Metrics::label('return_rate'), $this->float($now['return_rate'] ?? null), $this->float($then['return_rate'] ?? null), true),
        ]);

        if (isset($current['benefits'])) {
            // Points per movement kind AND direction: an adjustment can add or
            // remove points, and the two are never netted into one bar.
            $points = [];
            $directions = [];
            foreach ([$current['benefits']['loyalty'] ?? [], $previous['benefits']['loyalty'] ?? []] as $period => $movements) {
                foreach ((array) $movements as $movement) {
                    $kind = (string) $movement['kind'];
                    $direction = (string) ($movement['direction'] ?? '');
                    $points[$kind.'|'.$direction][$period] = ($points[$kind.'|'.$direction][$period] ?? 0) + (int) $movement['points'];
                    $directions[$kind][$direction] = true;
                }
            }
            $keys = array_keys($points);
            $label = static function (string $key) use ($directions): string {
                [$kind, $direction] = explode('|', $key, 2);
                $name = Lang::has('manager_advanced.loyalty.'.$kind) ? (string) __('manager_advanced.loyalty.'.$kind) : (string) __('manager_advanced.values.other');

                return count($directions[$kind] ?? []) > 1 && Lang::has('manager_advanced.direction.'.$direction)
                    ? $name.' · '.__('manager_advanced.direction.'.$direction)
                    : $name;
            };
            $cards[] = Cards::grouped(__('manager_advanced.cards.loyalty_points'), array_map($label, $keys), [
                ['label' => __('manager_advanced.period.current_series'), 'values' => array_map(static fn (string $key): int => $points[$key][0] ?? 0, $keys)],
                ['label' => __('manager_advanced.period.comparison_series'), 'values' => array_map(static fn (string $key): int => $points[$key][1] ?? 0, $keys)],
            ]);
            $stats = [];
            foreach (['package_redemptions', 'membership_uses', 'memberships_activated', 'packages_activated'] as $key) {
                if ((float) ($now[$key] ?? 0) !== 0.0 || (float) ($then[$key] ?? 0) !== 0.0) {
                    $stats[] = Metrics::card($key, $now, $then, $this->currency);
                }
            }
            $cards[] = Cards::stats(__('manager_advanced.cards.benefits'), $stats);
        }

        return $this->block('retention', 'loyalty', $cards);
    }

    /**
     * @param  list<array<string, mixed>|null>  $cards
     * @param  list<string>  $notes
     * @return array<string, mixed>|null
     */
    private function block(string $key, string $icon, array $cards, array $notes = []): ?array
    {
        $cards = array_values(array_filter($cards));

        return $cards === [] ? null : [
            'key' => $key,
            'title' => __('manager_advanced.sections.'.$key),
            'icon' => $icon,
            'cards' => $cards,
            'notes' => $notes,
        ];
    }

    /** @return list<array{label: string}> */
    private function axis(): array
    {
        return array_map(static fn (array $bucket): array => ['label' => $bucket['label']], $this->buckets);
    }

    /** @return list<string> */
    private function previousAxis(): array
    {
        return array_map(static fn (array $bucket): string => $bucket['label'], $this->previousBuckets);
    }

    /**
     * Ranked items of one reader dimension against the comparison period,
     * matched by the reader's own key (a service or employee id) — never by
     * name, which two people or a renamed service could share. An entity
     * present only in the comparison period is kept (0 now) so a drop shows.
     *
     * @param  array<array-key, mixed>  $rows  id => {name, $field, …}
     * @param  array<array-key, mixed>  $before  id => {name, $field, …}
     * @return list<array{label: string, value: int, previous: int}>
     */
    private function against(array $rows, array $before, string $field): array
    {
        $items = [];

        foreach ($rows + $before as $id => $row) {
            $items[] = [
                'label' => (string) ($row['name'] ?? '—'),
                'value' => (int) ($rows[$id][$field] ?? 0),
                'previous' => (int) ($before[$id][$field] ?? 0),
            ];
        }

        return $items;
    }

    private function float(int|float|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
