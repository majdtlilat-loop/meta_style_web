<?php

declare(strict_types=1);

namespace App\Livewire\Center\Dashboard;

use App\View\Label;
use App\View\Manager\FeatureOffer;
use Carbon\CarbonImmutable;

/**
 * The overview's charts and "now" cards as view arrays: titles, series,
 * items, empty states, links. Everything is already decided by the modules;
 * this only arranges and labels it, so the Blade stays free of logic.
 */
final class DashboardCards
{
    /** Entitlements the overview can upsell, with the permission that makes the upsell relevant. */
    private const UPSELL = [
        'booking' => 'appointment.view',
        'pos' => 'sale.view',
        'queue_management' => 'queue.view',
        'loyalty' => 'loyalty.view',
        'packages' => 'package.view',
        'memberships' => 'membership.view',
    ];

    public function __construct(
        private readonly DashboardPresenter $format,
        private readonly FeatureOffer $offers,
    ) {}

    /**
     * Chart rows. A row that lost its partner (a section the viewer cannot
     * see) takes the full width instead of leaving a hole.
     *
     * @param  array<string, mixed>  $sections
     * @param  list<array{key: string, label: string}>  $buckets
     * @return list<list<array<string, mixed>>>
     */
    public function charts(array $sections, array $buckets): array
    {
        $labels = array_map(static fn (array $bucket): array => ['label' => $bucket['label']], $buckets);
        $rows = [];

        if (isset($sections['bookings'])) {
            $b = $sections['bookings'];
            $rows[] = [
                $this->columns('bookings', __('manager_dashboard.charts.bookings'), $labels, [
                    ['label' => __('manager_dashboard.charts.this_period'), 'values' => $b['series']],
                    ['label' => __('manager_dashboard.charts.previous_period'), 'values' => $b['series_previous']],
                ], null, $this->format->number($b['total']), 8),
                $this->bars('bookings_by_status', __('manager_dashboard.charts.bookings_by_status'), $this->statusItems($b['status']), null, 4),
            ];
        }

        $commerce = [];

        if (isset($sections['sales'])) {
            $s = $sections['sales'];
            $series = [['label' => __('manager_dashboard.charts.billed'), 'values' => $s['series']]];

            if (isset($sections['payments']) && $sections['payments']['currency'] === $s['currency']) {
                $series[] = ['label' => __('manager_dashboard.charts.collected'), 'values' => $sections['payments']['series']];
            }

            $commerce[] = $this->columns('sales', count($series) > 1 ? __('manager_dashboard.charts.billed_vs_collected') : __('manager_dashboard.charts.sales'), $labels, $series, $s['currency'], $this->format->money($s['billed'], $s['currency']), 8);
            $commerce[] = $this->bars('sales_by_category', __('manager_dashboard.charts.sales_by_category'), array_map(fn (array $row): array => [
                'label' => $row['category'] ?? __('manager_dashboard.charts.kind.'.$row['kind']),
                'value' => $row['total_minor'],
                'meta' => trans_choice('manager_dashboard.charts.lines', $row['count'], ['count' => $this->format->number($row['count'])]),
            ], $s['categories']), $s['currency'], 4);
        } elseif (isset($sections['payments'])) {
            $p = $sections['payments'];
            $commerce[] = $this->columns('collected', __('manager_dashboard.charts.collected'), $labels, [['label' => __('manager_dashboard.charts.collected'), 'values' => $p['series']]], $p['currency'], $this->format->money($p['net'], $p['currency']), 8);
        }

        if ($commerce !== []) {
            $rows[] = $commerce;
        }

        $work = [];

        if (isset($sections['visits'])) {
            $v = $sections['visits'];
            $work[] = $this->bars('top_services', __('manager_dashboard.charts.top_services'), array_map(fn (array $service): array => [
                'label' => $service['name'],
                'value' => $service['completed'],
                'meta' => $service['minutes'] > 0 ? __('manager_dashboard.charts.minutes', ['minutes' => $this->format->number($service['minutes'])]) : null,
            ], $v['top_services']), null, 6, trans_choice('manager_dashboard.charts.services', $v['services_performed'], ['count' => $this->format->number($v['services_performed'])]));
            $work[] = $this->bars('performers', __('manager_dashboard.charts.performers'), array_map(fn (array $person): array => [
                'label' => $person['name'],
                'value' => $person['completed'],
                'meta' => $person['minutes'] > 0 ? __('manager_dashboard.charts.minutes', ['minutes' => $this->format->number($person['minutes'])]) : null,
            ], $v['performers']), null, 6, null, __('manager_dashboard.charts.performers_note'));
        }

        if ($work !== []) {
            $rows[] = $work;
        }

        $mix = [];

        if (isset($sections['sales'])) {
            $s = $sections['sales'];
            $mix[] = $this->bars('top_sellers', __('manager_dashboard.charts.top_sellers'), array_map(fn (array $item): array => [
                'label' => $item['name'],
                'value' => $item['total_minor'],
                'meta' => trans_choice('manager_dashboard.charts.sold', $item['count'], ['count' => $this->format->number($item['count'])]),
            ], $s['top_items']), $s['currency'], 6);
        }

        if (isset($sections['payments'])) {
            $p = $sections['payments'];
            $mix[] = $this->bars('payment_methods', __('manager_dashboard.charts.payment_methods'), array_map(static fn (array $row): array => [
                'label' => Label::for('pos_payment_method', $row['method']),
                'value' => $row['amount'],
            ], $p['methods']), $p['currency'], 6);
        }

        if ($mix !== []) {
            $rows[] = $mix;
        }

        $trends = [];

        if (isset($sections['customers'])) {
            $c = $sections['customers'];
            $trends[] = $this->columns('customer_growth', __('manager_dashboard.charts.customer_growth'), $labels, [['label' => __('manager_dashboard.kpi.new_customers'), 'values' => $c['series_new']]], null, $this->format->number($c['new']), 6);
        }

        if (isset($sections['queue'])) {
            $q = $sections['queue'];
            $trends[] = $this->columns('queue_trend', __('manager_dashboard.charts.queue_trend'), $labels, [['label' => __('manager_dashboard.kpi.queue_tickets'), 'values' => $q['series']]], null, $this->format->number($q['tickets']), 6);
        }

        if ($trends !== []) {
            $rows[] = $trends;
        }

        return array_map(static function (array $row): array {
            if (count($row) === 1) {
                $row[0]['span'] = 12;
            }

            return $row;
        }, $rows);
    }

    /**
     * @param  array<string, mixed>  $now
     * @return array<string, array<string, mixed>|null>
     */
    public function now(array $now, bool $multiBranch, string $locale): array
    {
        return [
            'today' => is_array($now['today'] ?? null) ? [
                'figure' => trans_choice('manager_dashboard.now.left_today', $now['today']['remaining'], ['count' => $this->format->number($now['today']['remaining'])]),
                'items' => array_map(fn (array $row): array => $this->appointment($row, $multiBranch, $locale, false), $now['today']['items']),
                'more' => ($more = max(0, $now['today']['remaining'] - count($now['today']['items']))) > 0
                    ? trans_choice('manager_dashboard.now.more', $more, ['count' => $this->format->number($more)])
                    : null,
                'href' => $this->format->link('center.calendar'),
            ] : null,
            'upcoming' => is_array($now['upcoming'] ?? null) ? [
                'items' => array_map(fn (array $row): array => $this->appointment($row, $multiBranch, $locale, true), $now['upcoming']),
                'href' => $this->format->link('center.calendar'),
            ] : null,
            'queue' => is_array($now['queue'] ?? null) ? [
                'states' => array_map(fn (string $state): array => [
                    'state' => $state,
                    'label' => Label::for('ticket_state', $state),
                    'value' => $this->format->number((int) ($now['queue']['states'][$state] ?? 0)),
                ], ['waiting', 'called', 'serving', 'held']),
                'open' => (int) $now['queue']['open'],
                'longest' => $now['queue']['longest_wait_minutes'] !== null
                    ? __('manager_dashboard.live.longest_wait', ['minutes' => $this->format->number($now['queue']['longest_wait_minutes'])])
                    : null,
                'href' => $this->format->link('center.queue'),
            ] : null,
            'sales' => is_array($now['sales'] ?? null) ? [
                'items' => array_map(fn (array $sale): array => [
                    'number' => $sale['number'],
                    'customer' => $sale['customer'] ?? __('manager_dashboard.now.walk_in_customer'),
                    'minor' => $sale['total_minor'],
                    'currency' => $sale['currency'],
                    'when' => $this->when($sale['local_date'], $sale['local_time'], $locale),
                    'voided' => $sale['status'] === 'voided',
                    'branch' => $multiBranch ? $sale['branch'] : null,
                ], $now['sales']),
                'href' => $this->format->link('center.sales'),
            ] : null,
            'customers' => is_array($now['customers'] ?? null) ? [
                'items' => array_map(static fn (array $customer): array => [
                    'name' => $customer['name'],
                    'initial' => mb_strtoupper(mb_substr((string) $customer['name'], 0, 1)),
                    'registered' => $customer['registered'],
                    'added' => $customer['created_at'] !== null ? CarbonImmutable::parse($customer['created_at'])->locale($locale)->diffForHumans() : null,
                ], $now['customers']),
                'href' => $this->format->link('center.customers'),
            ] : null,
            'team' => ($now['team'] ?? null) !== null ? [
                'active' => trans_choice('manager_dashboard.now.team_active', (int) $now['team'], ['count' => $this->format->number((int) $now['team'])]),
                'booked' => is_array($now['team_booked'] ?? null) ? array_map(fn (array $person): array => [
                    'name' => $person['name'],
                    'initial' => mb_strtoupper(mb_substr($person['name'], 0, 1)),
                    'bookings' => trans_choice('manager_dashboard.now.bookings_count', $person['bookings'], ['count' => $this->format->number($person['bookings'])]),
                ], array_slice($now['team_booked'], 0, 6)) : null,
                // What the count means, as a tooltip — never attendance.
                'note' => match (true) {
                    ! is_array($now['team_booked'] ?? null) => __('manager_dashboard.now.team_note'),
                    $now['team_booked'] !== [] => __('manager_dashboard.now.team_booked_note'),
                    default => null,
                },
                'href' => $this->format->link('center.staff'),
            ] : null,
        ];
    }

    /**
     * Features the viewer would use (they hold the permission) that the
     * center's plan does not include: one compact line each, never a fake
     * number.
     *
     * @return list<array{name: string, label: string, href: string|null}>
     */
    public function locked(callable $can): array
    {
        $locked = [];

        foreach (self::UPSELL as $feature => $permission) {
            if (! $can($permission) || ! $this->offers->isLocked($feature)) {
                continue;
            }

            $offer = $this->offers->for($feature);

            if ($offer !== null) {
                $locked[] = ['name' => $offer['name'], 'label' => $offer['lock_label'], 'href' => $offer['links']['plans']];
            }
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function appointment(array $row, bool $multiBranch, string $locale, bool $withDate): array
    {
        $details = array_filter([
            implode(', ', $row['services']),
            implode(', ', $row['employees']),
            $multiBranch ? $row['branch'] : null,
        ], static fn (?string $part): bool => $part !== null && $part !== '');

        return [
            'time' => $withDate ? $this->when($row['local_date'], $row['local_start'], $locale) : $row['local_start'].'–'.$row['local_end'],
            'customer' => $row['customer'] ?? __('manager_dashboard.now.guest'),
            'details' => implode(' · ', $details),
            'status' => $row['in_progress'] ? 'in_progress' : $row['status'],
            'status_label' => $row['in_progress'] ? __('manager_dashboard.now.in_progress') : Label::for('appointment_status', $row['status']),
        ];
    }

    private function when(string $date, string $time, string $locale): string
    {
        return CarbonImmutable::parse($date.' '.$time)->locale($locale)->isoFormat('ddd D MMM · HH:mm');
    }

    /**
     * @param  array<string, int>  $status
     * @return list<array{label: string, value: int}>
     */
    private function statusItems(array $status): array
    {
        $items = [];

        foreach (['booked', 'confirmed', 'completed', 'cancelled', 'no_show'] as $state) {
            if (($status[$state] ?? 0) > 0) {
                $items[] = ['label' => Label::for('appointment_status', $state), 'value' => (int) $status[$state]];
            }
        }

        return $items;
    }

    /**
     * @param  list<array{label: string}>  $buckets
     * @param  list<array{label: string, values: list<int>}>  $series
     * @return array<string, mixed>
     */
    private function columns(string $id, string $title, array $buckets, array $series, ?string $currency, ?string $figure, int $span): array
    {
        $total = 0;
        foreach ($series as $one) {
            $total += array_sum($one['values']);
        }

        return [
            'id' => $id,
            'type' => 'columns',
            'title' => $title,
            'buckets' => $buckets,
            'series' => $series,
            'currency' => $currency,
            'figure' => $figure,
            'span' => $span,
            'empty' => $total === 0 || $buckets === [],
            'note' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function bars(string $id, string $title, array $items, ?string $currency, int $span, ?string $figure = null, ?string $note = null): array
    {
        return [
            'id' => $id,
            'type' => 'bars',
            'title' => $title,
            'items' => $items,
            'currency' => $currency,
            'figure' => $figure,
            'span' => $span,
            'empty' => $items === [],
            'note' => $note,
        ];
    }
}
