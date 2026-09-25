<?php

declare(strict_types=1);

namespace App\View\Reports\Standard;

use App\Modules\Reports\Application\Analytics\BookingFacts;
use App\View\Label;
use Carbon\CarbonImmutable;

/**
 * The operational views: Booking activity, Visit & service delivery,
 * Employee delivery and Queue operations.
 */
final class OperationsLayouts
{
    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function bookings(array $facts, Cards $cards, string $locale): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $lines = static fn (array $rows): array => array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['current'], 'previous' => $row['previous']], $rows);
        $breakdown = fn (array $rows, bool $category): array => array_map(fn (array $row): array => array_values(array_filter([
            Cards::cell($row['name']),
            $category ? Cards::cell((string) ($row['row']['category'] ?? '—')) : null,
            $cards->number($row['current']),
            $cards->number((int) ($row['row']['status']['completed'] ?? 0)),
            $cards->number((int) ($row['row']['status']['cancelled'] ?? 0)),
            $cards->number((int) ($row['row']['status']['no_show'] ?? 0)),
            $cards->change($row['current'], $row['previous']),
        ])), $rows);

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('scheduled', $metrics, $series),
                $cards->kpi('completed', $metrics, $series),
                $cards->kpi('cancelled', $metrics),
                $cards->kpi('no_show', $metrics),
                $cards->kpi('cancellation_rate', $metrics),
                $cards->kpi('no_show_rate', $metrics),
                $cards->kpi('upcoming', $metrics),
                $cards->kpi('created', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [
                    $cards->line('scheduled', $series['scheduled'] ?? null, false),
                    $cards->stacked('status_over_time', array_map(static fn (string $status): array => [
                        'label' => Label::for('appointment_status', $status),
                        'values' => $parts['status_series'][$status] ?? [],
                        'status' => $status,
                    ], BookingFacts::STATUSES)),
                ],
                'distribution' => [
                    $cards->donut('booking_status', CommerceLayouts::statuses($parts['status']), 'status'),
                    $cards->radial('completion', $parts['completion']['current'], $parts['completion']['previous'], 'accent', Cards::label('captions', 'of_outcomes')),
                    $cards->donut('sources', array_map(static fn (string $source, int $count): array => ['label' => Cards::value('source', $source), 'value' => $count], array_keys($parts['sources']), array_values($parts['sources']))),
                ],
                'comparison' => [
                    $cards->ranked('booked_by_service', $lines($parts['services'])),
                    $cards->ranked('booked_by_employee', $lines($parts['employees'])),
                    $cards->ranked('bookings_by_branch', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['current'], 'previous' => $row['previous']], $parts['branches'])),
                ],
                'peak' => [$this->heatmap($cards, 'peak_booking_times', $parts['heat'], $locale)],
                'details' => [
                    $cards->table('services_booked', [
                        ['key' => 'service'], ['key' => 'category'], ['key' => 'booked', 'numeric' => true], ['key' => 'completed', 'numeric' => true],
                        ['key' => 'cancelled', 'numeric' => true], ['key' => 'no_show', 'numeric' => true], ['key' => 'change', 'numeric' => true],
                    ], $breakdown($parts['services'], true)),
                    $cards->table('employees_booked', [
                        ['key' => 'employee'], ['key' => 'booked', 'numeric' => true], ['key' => 'completed', 'numeric' => true],
                        ['key' => 'cancelled', 'numeric' => true], ['key' => 'no_show', 'numeric' => true], ['key' => 'change', 'numeric' => true],
                    ], $breakdown($parts['employees'], false)),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function services(array $facts, Cards $cards): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $ranked = static fn (array $rows): array => array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['current'], 'previous' => $row['previous']], $rows);

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('completed_services', $metrics, $series),
                $cards->kpi('service_minutes', $metrics),
                $cards->kpi('average_service_minutes', $metrics),
                $cards->kpi('arrivals', $metrics, $series),
                $cards->kpi('completed_visits', $metrics),
                $cards->kpi('walk_ins', $metrics),
                $cards->kpi('aborted_visits', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [$cards->line('completed_services', $series['completed_services'] ?? null)],
                'distribution' => [
                    $cards->donut('category_mix', array_map(static fn (array $row): array => ['label' => $row['name'] !== '' ? $row['name'] : Cards::label('values', 'no_category'), 'value' => $row['current']], $parts['categories'])),
                ],
                'comparison' => [
                    $cards->ranked('top_services', $ranked($parts['services'])),
                    isset($parts['booked']) ? $cards->ranked('most_booked', $ranked($parts['booked'])) : null,
                    isset($parts['billed']) ? $cards->ranked('top_services_billed', array_map(fn (array $row): array => [
                        'label' => $row['name'],
                        'value' => $row['current'],
                        'previous' => $row['previous'],
                        'meta' => $row['quantity'] > 0 ? Cards::label('captions', 'average').' '.$cards->number((int) round($row['current'] / $row['quantity']), 'money')['text'] : null,
                    ], $parts['billed']), 'money') : null,
                    $cards->ranked('lowest_services', $ranked($parts['lowest']), sort: false, limit: 5),
                ],
                'details' => [
                    $cards->table('services_performed', [
                        ['key' => 'service'], ['key' => 'category'], ['key' => 'performed', 'numeric' => true], ['key' => 'previous', 'numeric' => true],
                        ['key' => 'change', 'numeric' => true], ['key' => 'minutes', 'numeric' => true], ['key' => 'average_minutes', 'numeric' => true],
                    ], array_map(fn (array $row): array => [
                        Cards::cell($row['name']),
                        Cards::cell((string) ($row['category'] ?? '—')),
                        $cards->number($row['current']),
                        $cards->number($row['previous']),
                        $cards->change($row['current'], $row['previous']),
                        $cards->number((int) ($row['row']['minutes'] ?? 0), 'minutes'),
                        $cards->number($row['current'] > 0 ? round((int) ($row['row']['minutes'] ?? 0) / $row['current'], 1) : null, 'minutes'),
                    ], $parts['services'])),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function team(array $facts, Cards $cards): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $employees = $parts['employees'];
        $columns = [['key' => 'employee'], ['key' => 'performed', 'numeric' => true], ['key' => 'change', 'numeric' => true], ['key' => 'minutes', 'numeric' => true], ['key' => 'average_minutes', 'numeric' => true]];

        if ($parts['booked_known']) {
            $columns[] = ['key' => 'booked', 'numeric' => true];
        }

        if ($parts['rated']) {
            $columns[] = ['key' => 'rating', 'numeric' => true];
        }

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('completed_services', $metrics, $series),
                $cards->kpi('actual_performers', $metrics),
                $cards->kpi('service_minutes', $metrics),
                $cards->kpi('average_service_minutes', $metrics),
                $cards->kpi('booked_services', $metrics),
                $cards->kpi('employee_rating', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [$cards->line('completed_services', $series['completed_services'] ?? null)],
                'comparison' => [
                    $cards->ranked('performed_by_employee', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['current'], 'previous' => $row['previous']], $employees), limit: 8),
                    $cards->donut('employee_share', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['current']], $employees)),
                    $cards->ranked('minutes_by_employee', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['minutes']], $employees), 'minutes', limit: 8),
                    $cards->ranked('employee_ratings', CustomerLayouts::rated($parts['ratings']), limit: 8, additive: false),
                ],
                'details' => [
                    $cards->table('team', $columns, array_map(fn (array $row): array => array_values(array_filter([
                        Cards::cell($row['name']),
                        $cards->number($row['current']),
                        $cards->change($row['current'], $row['previous']),
                        $cards->number($row['minutes'], 'minutes'),
                        $cards->number($row['current'] > 0 ? round($row['minutes'] / $row['current'], 1) : null, 'minutes'),
                        $parts['booked_known'] ? $cards->number($row['booked']) : null,
                        $parts['rated'] ? ($row['rating'] !== null ? Cards::cell(number_format((float) $row['rating'], 1).' · '.trans_choice('manager_reports.std.captions.ratings', (int) $row['ratings'], ['count' => number_format((int) $row['ratings'])]), (float) $row['rating']) : Cards::cell('—', '')) : null,
                    ])), $employees)),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function queue(array $facts, Cards $cards, string $locale): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('tickets', $metrics, $series),
                $cards->kpi('closed', $metrics),
                $cards->kpi('first_call', $metrics),
                $cards->kpi('service_start', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [$cards->line('tickets', $series['tickets'] ?? null)],
                'distribution' => [
                    $cards->donut('ticket_states', array_map(static fn (string $state, int $count): array => ['label' => Label::for('ticket_state', $state), 'value' => $count], array_keys($parts['states']), array_values($parts['states']))),
                    $cards->donut('ticket_sources', array_map(static fn (string $source, int $count): array => ['label' => Cards::value('queue_source', $source), 'value' => $count], array_keys($parts['sources']), array_values($parts['sources']))),
                ],
                'peak' => [$this->heatmap($cards, 'peak_queue_times', $parts['heat'], $locale)],
                'details' => [
                    $cards->table('queue_days', [
                        ['key' => 'date'], ['key' => 'tickets', 'numeric' => true], ['key' => 'first_call', 'numeric' => true], ['key' => 'service_start', 'numeric' => true],
                    ], array_map(static fn (array $row): array => [
                        Cards::cell(CarbonImmutable::parse((string) $row['date'])->locale($locale)->isoFormat('ddd D MMM'), (string) $row['date']),
                        $cards->number((int) $row['tickets']),
                        $cards->number($row['average_first_call_seconds'], 'seconds'),
                        $cards->number($row['average_service_start_seconds'], 'seconds'),
                    ], array_reverse($parts['daily']))),
                ],
            ]),
        ];
    }

    /**
     * Day of week × hour, Monday first, trimmed to the hours that had any
     * activity so the grid stays readable.
     *
     * @param  array<int, array<int, int>>  $heat  [ISO weekday][hour] => count
     * @return array<string, mixed>|null
     */
    private function heatmap(Cards $cards, string $id, array $heat, string $locale): ?array
    {
        $hours = [];

        foreach ($heat as $byHour) {
            foreach ($byHour as $hour => $count) {
                if ($count > 0) {
                    $hours[] = (int) $hour;
                }
            }
        }

        if ($hours === []) {
            return null;
        }

        $range = range(min($hours), max($hours));
        $monday = CarbonImmutable::parse('2026-01-05')->locale($locale);
        $rows = [];
        $values = [];

        for ($day = 1; $day <= 7; $day++) {
            $rows[] = $monday->addDays($day - 1)->isoFormat('ddd');
            $values[] = array_map(static fn (int $hour): int => (int) ($heat[$day][$hour] ?? 0), $range);
        }

        return $cards->heatmap($id, $rows, array_map(static fn (int $hour): string => sprintf('%02d', $hour), $range), $values, Cards::label('columns', 'day'), Cards::label('columns', 'hour'));
    }
}
