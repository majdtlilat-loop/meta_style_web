<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

use App\Kernel\Authorization\Permission;

/**
 * Visit & service delivery: the services actually PERFORMED (completed
 * stages of visits that arrived in the period) and their observed minutes.
 * "Most booked" (reserved lines) and "highest billed" (invoice lines) are
 * separate facts, shown only to a viewer who may read bookings or sales.
 */
final class ServiceFacts
{
    /** How many services the top list names before folding the rest. */
    public const TOP = 7;

    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->journeys(), $reads->journeys(true)];
        $count = count($reads->buckets);
        $stage = static fn (array $data, string $key): int => (int) ($data['stages'][$key] ?? 0);

        $completed = $stage($now, 'completed');
        $completedBefore = $stage($then, 'completed');
        $minutes = $stage($now, 'minutes');
        $minutesBefore = $stage($then, 'minutes');

        $performed = $now['stages']['services'] ?? [];
        $performedBefore = $then['stages']['services'] ?? [];
        $services = Facts::compare(
            $performed,
            $performedBefore,
            static fn (array $row): int => (int) $row['completed'],
            static fn (array $row): string => (string) $row['name'],
        );

        // The Catalog names each service's menu category; the grouping is ours.
        $categoryOf = $reads->serviceCategories(array_values(array_unique(array_map('intval', [...array_keys($performed), ...array_keys($performedBefore)]))));
        $categories = Facts::compare(
            $this->byCategory($performed, $categoryOf),
            $this->byCategory($performedBefore, $categoryOf),
            static fn (array $row): int => $row['completed'],
            static fn (array $row): string => $row['name'],
        );

        foreach ($services as $index => $row) {
            $services[$index]['category'] = $categoryOf[(int) $row['id']]['name'] ?? null;
        }

        $active = array_values(array_filter($services, static fn (array $row): bool => $row['current'] > 0 || $row['previous'] > 0));
        $parts = [
            'services' => $services,
            'categories' => $categories,
            // The weakest of the services that ran in either period — only
            // when there are more than the top list names.
            'lowest' => count($active) > self::TOP ? array_slice(array_reverse($active), 0, 5) : [],
        ];

        if ($reads->may(Permission::AppointmentView)) {
            $parts['booked'] = Facts::compare(
                $reads->bookings()['services'] ?? [],
                $reads->bookings(true)['services'] ?? [],
                static fn (array $row): int => (int) $row['total'],
                static fn (array $row): string => (string) $row['name'],
            );
        }

        // Invoice lines know no employee or category filter: unfiltered
        // sales never sit beside filtered deliveries.
        if ($reads->may(Permission::SaleView) && ! $reads->filtered()) {
            $sales = $reads->sales();
            $salesBefore = $reads->sales(true);
            $lead = Facts::leadCurrency($sales['billed'], $salesBefore['billed']);
            $parts['billed'] = Facts::items($sales['items'], $salesBefore['items'], $lead, 'service');
            $parts['billed_currency'] = $lead;
        }

        return [
            'empty' => (int) $now['total'] === 0,
            'currency' => $parts['billed_currency'] ?? null,
            'other_currencies' => [],
            'metrics' => [
                'completed_services' => Facts::metric($completed, $completedBefore),
                'service_minutes' => Facts::metric($minutes, $minutesBefore, 'minutes'),
                'average_service_minutes' => Facts::metric(Facts::mean($minutes, $completed), Facts::mean($minutesBefore, $completedBefore), 'minutes', null),
                'arrivals' => Facts::metric((int) $now['total'], (int) $then['total']),
                'completed_visits' => Facts::metric((int) ($now['status']['completed'] ?? 0), (int) ($then['status']['completed'] ?? 0)),
                'walk_ins' => Facts::metric((int) ($now['sources']['walk_in'] ?? 0), (int) ($then['sources']['walk_in'] ?? 0)),
                'aborted_visits' => Facts::metric((int) ($now['status']['aborted'] ?? 0), (int) ($then['status']['aborted'] ?? 0), 'number', false),
            ],
            'series' => [
                'completed_services' => Facts::pair(
                    Facts::series($now['stages']['daily'] ?? [], $now['stages']['hourly'] ?? [], $reads->buckets),
                    Facts::aligned($then['stages']['daily'] ?? [], $then['stages']['hourly'] ?? [], $reads->previousBuckets, $count),
                ),
                'arrivals' => Facts::pair(Facts::series($now['daily'], $now['hourly'], $reads->buckets), null),
            ],
            'parts' => $parts,
        ];
    }

    /**
     * Performed services summed per menu category (0 = no category).
     *
     * @param  array<int, array{name: string, completed: int, minutes: int}>  $services
     * @param  array<int, array{id: int, name: string}|null>  $categoryOf
     * @return array<int, array{name: string, completed: int, minutes: int}>
     */
    private function byCategory(array $services, array $categoryOf): array
    {
        $grouped = [];

        foreach ($services as $id => $row) {
            $category = $categoryOf[(int) $id] ?? null;
            $key = $category['id'] ?? 0;
            $grouped[$key] ??= ['name' => $category['name'] ?? '', 'completed' => 0, 'minutes' => 0];
            $grouped[$key]['completed'] += (int) $row['completed'];
            $grouped[$key]['minutes'] += (int) $row['minutes'];
        }

        return $grouped;
    }
}
