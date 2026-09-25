<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

/**
 * Customer activity, from observed visits in the viewer's branches: who
 * came, who was new (no earlier visit in scope) and who came back. The
 * top customers carry a name and a visit count only — never a contact
 * detail.
 */
final class CustomerFacts
{
    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->customers(), $reads->customers(true)];
        $served = (int) $now['customers'];
        $servedBefore = (int) $then['customers'];

        return [
            'empty' => $served === 0,
            'currency' => null,
            'other_currencies' => [],
            'metrics' => [
                'customers' => Facts::metric($served, $servedBefore),
                'new' => Facts::metric((int) $now['new'], (int) $then['new']),
                'returning' => Facts::metric((int) $now['returning'], (int) $then['returning']),
                'repeat_rate' => Facts::metric(Facts::rate((int) $now['returning'], $served), Facts::rate((int) $then['returning'], $servedBefore), 'percent'),
                'visits' => Facts::metric((int) $now['visits'], (int) $then['visits']),
                'visit_frequency' => Facts::metric($now['visit_frequency'] ?? null, $then['visit_frequency'] ?? null, 'decimal'),
            ],
            'series' => [
                'new' => Facts::pair(
                    Facts::series($now['daily_new'] ?? [], $now['hourly_new'] ?? [], $reads->buckets),
                    Facts::aligned($then['daily_new'] ?? [], $then['hourly_new'] ?? [], $reads->previousBuckets, count($reads->buckets)),
                ),
            ],
            'parts' => [
                'mix' => ['new' => (int) $now['new'], 'returning' => (int) $now['returning']],
                'frequency' => $now['frequency'] ?? [],
                'frequency_previous' => $then['frequency'] ?? [],
                'top' => $now['top'] ?? [],
            ],
        ];
    }
}
