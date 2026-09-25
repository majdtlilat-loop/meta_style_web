<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

/**
 * Queue operations: tickets issued in the period, how long people waited to
 * be called and to be served (tickets carrying that timestamp only), and
 * when the queue is busiest. Abandonment is not recorded, so never shown.
 */
final class QueueFacts
{
    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->queue(), $reads->queue(true)];
        $count = count($reads->buckets);
        $daily = static fn (array $data, string $field): array => array_combine(
            array_map(static fn (array $row): string => (string) $row['date'], $data['daily']),
            array_map(static fn (array $row): int => (int) ($row[$field] ?? 0), $data['daily']),
        );

        return [
            'empty' => (int) $now['total'] === 0,
            'currency' => null,
            'other_currencies' => [],
            'metrics' => [
                'tickets' => Facts::metric((int) $now['total'], (int) $then['total']),
                'closed' => Facts::metric((int) $now['closed'], (int) $then['closed']),
                'first_call' => Facts::metric($now['average_first_call_seconds'], $then['average_first_call_seconds'], 'seconds', false),
                'service_start' => Facts::metric($now['average_service_start_seconds'], $then['average_service_start_seconds'], 'seconds', false),
            ],
            'series' => [
                'tickets' => Facts::pair(
                    Facts::series($daily($now, 'tickets'), $now['hourly'] ?? [], $reads->buckets),
                    Facts::aligned($daily($then, 'tickets'), $then['hourly'] ?? [], $reads->previousBuckets, $count),
                ),
            ],
            'parts' => [
                'states' => $now['states'],
                'sources' => $now['sources'],
                'heat' => $now['heat'] ?? [],
                'daily' => $now['daily'],
                'samples' => ['first_call' => (int) $now['first_call_sample'], 'service_start' => (int) $now['service_start_sample']],
            ],
        ];
    }
}
