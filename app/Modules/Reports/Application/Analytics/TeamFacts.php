<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

use App\Kernel\Authorization\Permission;

/**
 * Employee delivery: the services each employee actually PERFORMED (the
 * employee on the completed stage), their observed minutes, the lines booked
 * with them and the ratings their work received. There is no employee score
 * and no sales per employee: payments are not attributed to performers
 * (docs/28 §4).
 */
final class TeamFacts
{
    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->journeys(), $reads->journeys(true)];
        $employees = $now['stages']['employees'] ?? [];
        $employeesBefore = $then['stages']['employees'] ?? [];
        $completed = (int) ($now['stages']['completed'] ?? 0);
        $completedBefore = (int) ($then['stages']['completed'] ?? 0);
        $minutes = (int) ($now['stages']['minutes'] ?? 0);
        $minutesBefore = (int) ($then['stages']['minutes'] ?? 0);

        $rows = Facts::compare(
            $employees,
            $employeesBefore,
            static fn (array $row): int => (int) $row['completed'],
            static fn (array $row): string => (string) $row['name'],
        );

        $metrics = [
            'actual_performers' => Facts::metric(count($employees), count($employeesBefore), 'number', null),
            'completed_services' => Facts::metric($completed, $completedBefore),
            'service_minutes' => Facts::metric($minutes, $minutesBefore, 'minutes'),
            'average_service_minutes' => Facts::metric(Facts::mean($minutes, $completed), Facts::mean($minutesBefore, $completedBefore), 'minutes', null),
        ];

        $booked = [];
        $ratings = [];

        if ($reads->may(Permission::AppointmentView)) {
            $bookings = $reads->bookings();
            $bookingsBefore = $reads->bookings(true);
            $booked = $bookings['employees'] ?? [];
            $metrics['booked_services'] = Facts::metric(
                array_sum(array_map(static fn (array $row): int => (int) $row['total'], $booked)),
                array_sum(array_map(static fn (array $row): int => (int) $row['total'], $bookingsBefore['employees'] ?? [])),
            );
        }

        // Ratings do not follow the employee / service filters: shown only
        // for the whole team, never beside a filtered delivery count.
        if ($reads->may(Permission::ReviewView) && ! $reads->filtered()) {
            $ratings = $reads->reviews()['employees'] ?? [];
            $all = $reads->reviews()['dimensions']['employee'] ?? null;
            $allBefore = $reads->reviews(true)['dimensions']['employee'] ?? null;

            if ($ratings !== [] || $all !== null) {
                $metrics['employee_rating'] = Facts::metric($all['average'] ?? null, $allBefore['average'] ?? null, 'rating');
            }
        }

        // Booked but nothing performed yet is still part of the team's period.
        $listed = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        foreach ($booked as $id => $line) {
            if (! in_array((int) $id, $listed, true)) {
                $rows[] = ['id' => (int) $id, 'name' => (string) $line['name'], 'current' => 0, 'previous' => 0, 'row' => null, 'previous_row' => null];
            }
        }

        foreach ($rows as $index => $row) {
            $id = (int) $row['id'];
            $rows[$index]['minutes'] = (int) ($row['row']['minutes'] ?? 0);
            $rows[$index]['booked'] = isset($booked[$id]) ? (int) $booked[$id]['total'] : ($reads->may(Permission::AppointmentView) ? 0 : null);
            $rows[$index]['booked_completed'] = isset($booked[$id]) ? (int) ($booked[$id]['status']['completed'] ?? 0) : null;
            $rows[$index]['rating'] = $ratings[$id]['average'] ?? null;
            $rows[$index]['ratings'] = $ratings[$id]['count'] ?? null;
        }

        return [
            'empty' => (int) $now['total'] === 0 && $booked === [],
            'currency' => null,
            'other_currencies' => [],
            'metrics' => $metrics,
            'series' => [
                'completed_services' => Facts::pair(
                    Facts::series($now['stages']['daily'] ?? [], $now['stages']['hourly'] ?? [], $reads->buckets),
                    Facts::aligned($then['stages']['daily'] ?? [], $then['stages']['hourly'] ?? [], $reads->previousBuckets, count($reads->buckets)),
                ),
            ],
            'parts' => [
                'employees' => $rows,
                'ratings' => array_map(static fn (int $id, array $row): array => ['id' => $id] + $row, array_keys($ratings), $ratings),
                'rated' => $ratings !== [],
                'booked_known' => $reads->may(Permission::AppointmentView),
            ],
        ];
    }
}
