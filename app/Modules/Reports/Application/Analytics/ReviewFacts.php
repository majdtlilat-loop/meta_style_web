<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

/**
 * Review summary: visible reviews (hidden ones never count), the overall
 * rating distribution, and the employee and service ratings anchored to the
 * stage that was actually performed.
 */
final class ReviewFacts
{
    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->reviews(), $reads->reviews(true)];
        $low = static fn (array $data): int => (int) ($data['distribution'][1] ?? 0) + (int) ($data['distribution'][2] ?? 0);
        $top = static fn (array $data): ?float => Facts::rate((int) ($data['distribution'][5] ?? 0), (int) $data['count']);
        $named = static fn (array $rows): array => array_map(static fn (int $id, array $row): array => ['id' => $id] + $row, array_keys($rows), $rows);

        return [
            'empty' => (int) $now['count'] === 0,
            'currency' => null,
            'other_currencies' => [],
            'metrics' => [
                'reviews' => Facts::metric((int) $now['count'], (int) $then['count']),
                'average' => Facts::metric($now['average'], $then['average'], 'rating'),
                'five_star_share' => Facts::metric($top($now), $top($then), 'percent'),
                'low_ratings' => Facts::metric($low($now), $low($then), 'number', false),
            ],
            'series' => [
                'reviews' => Facts::pair(
                    Facts::series($now['daily'] ?? [], null, $reads->buckets),
                    Facts::aligned($then['daily'] ?? [], null, $reads->previousBuckets, count($reads->buckets)),
                ),
            ],
            'parts' => [
                'distribution' => $now['distribution'],
                'distribution_previous' => $then['distribution'],
                'employees' => $named($now['employees'] ?? []),
                'services' => $named($now['services'] ?? []),
            ],
        ];
    }
}
