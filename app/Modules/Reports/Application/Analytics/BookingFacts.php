<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

use Carbon\CarbonImmutable;

/**
 * Booking activity: scheduled work in the period (by its start), its
 * outcomes and where it came from, by the service and the employee each
 * line RESERVED — never who actually performed it (that is the Team view).
 *
 * Rates share one denominator, bookings that reached an outcome, so the
 * completion, cancellation and no-show rates always add up to 100 %.
 */
final class BookingFacts
{
    /** Status order of every status chart: open first, then the outcomes. */
    public const STATUSES = ['booked', 'confirmed', 'completed', 'no_show', 'cancelled'];

    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->bookings(), $reads->bookings(true)];
        $count = count($reads->buckets);
        $status = static fn (array $data, string $key): int => (int) ($data['status'][$key] ?? 0);
        $rate = static fn (array $data, string $key): ?float => Facts::rate($status($data, $key), Facts::resolved($data['status']));

        $metrics = [
            'scheduled' => Facts::metric((int) $now['total'], (int) $then['total']),
            'completed' => Facts::metric($status($now, 'completed'), $status($then, 'completed')),
            'cancelled' => Facts::metric($status($now, 'cancelled'), $status($then, 'cancelled'), 'number', false),
            'no_show' => Facts::metric($status($now, 'no_show'), $status($then, 'no_show'), 'number', false),
            'cancellation_rate' => Facts::metric($rate($now, 'cancelled'), $rate($then, 'cancelled'), 'percent', false),
            'no_show_rate' => Facts::metric($rate($now, 'no_show'), $rate($then, 'no_show'), 'percent', false),
            'created' => Facts::metric((int) $now['created'], (int) $then['created']),
        ];

        // "Still to come" only means something while the period is running:
        // a period that ended before today has none, by construction.
        if (! $reads->period->current->to->lessThan(CarbonImmutable::now($reads->period->current->timezone)->startOfDay())) {
            $metrics['upcoming'] = Facts::metric((int) ($now['upcoming'] ?? 0), null, 'number', null);
        }

        $byStatus = [];

        foreach (self::STATUSES as $key) {
            $daily = array_map(static fn (array $counts): int => (int) ($counts[$key] ?? 0), $now['daily_status'] ?? []);
            $hourly = array_map(static fn (array $counts): int => (int) ($counts[$key] ?? 0), $now['hourly_status'] ?? []);
            $byStatus[$key] = Facts::series($daily, $hourly, $reads->buckets) ?? [];
        }

        $line = static fn (array $row): int => (int) $row['total'];
        $name = static fn (array $row): string => (string) $row['name'];

        return [
            'empty' => (int) $now['total'] === 0 && (int) $now['created'] === 0,
            'currency' => null,
            'other_currencies' => [],
            'metrics' => $metrics,
            'series' => [
                'scheduled' => Facts::pair(
                    Facts::series($now['daily'], $now['hourly'], $reads->buckets),
                    Facts::aligned($then['daily'], $then['hourly'], $reads->previousBuckets, $count),
                ),
                'completed' => Facts::pair(Facts::series(array_map(static fn (array $c): int => (int) ($c['completed'] ?? 0), $now['daily_status'] ?? []), array_map(static fn (array $c): int => (int) ($c['completed'] ?? 0), $now['hourly_status'] ?? []), $reads->buckets), null),
            ],
            'parts' => [
                'status' => $now['status'],
                'status_series' => $byStatus,
                'sources' => $now['sources'],
                'sources_previous' => $then['sources'],
                'services' => Facts::compare($now['services'] ?? [], $then['services'] ?? [], $line, $name),
                'employees' => Facts::compare($now['employees'] ?? [], $then['employees'] ?? [], $line, $name),
                'heat' => $now['heat'] ?? [],
                'completion' => ['current' => $rate($now, 'completed'), 'previous' => $rate($then, 'completed')],
                'branches' => $reads->branchCount() > 1 ? $this->branches($reads, $now, $then) : [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $then
     * @return list<array{id: int, name: string, current: int, previous: int}>
     */
    private function branches(AnalyticsReads $reads, array $now, array $then): array
    {
        $rows = [];

        foreach ($reads->branchNames() as $id => $name) {
            $rows[] = ['id' => $id, 'name' => $name, 'current' => (int) ($now['branches'][$id] ?? 0), 'previous' => (int) ($then['branches'][$id] ?? 0)];
        }

        return $rows;
    }
}
