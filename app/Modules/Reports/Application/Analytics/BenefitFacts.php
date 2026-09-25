<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

/**
 * Benefit usage: loyalty points in and out, package sessions redeemed,
 * membership benefit uses, and memberships / packages sold — each in its own
 * unit, never added together. Movements are the branch-scoped ones linked to
 * a sale or an invoice.
 */
final class BenefitFacts
{
    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$now, $then] = [$reads->benefits(), $reads->benefits(true)];
        $packages = static fn (array $data): int => (int) ($data['packages']['redemption'] ?? 0);
        $uses = static fn (array $data): int => (int) ($data['memberships']['use'] ?? 0);
        $sold = static fn (array $data, string $key): int => (int) ($data['activations'][$key] ?? 0);

        $movements = [];

        foreach ([[$now, 'current'], [$then, 'previous']] as [$data, $slot]) {
            foreach ($data['loyalty'] ?? [] as $row) {
                $key = $row['kind'].'|'.$row['direction'];
                $movements[$key] ??= ['kind' => (string) $row['kind'], 'direction' => (string) $row['direction'], 'current' => 0, 'previous' => 0];
                $movements[$key][$slot] += (int) $row['points'];
            }
        }

        $movements = array_values($movements);
        usort($movements, static fn (array $a, array $b): int => [$b['current'], $b['previous']] <=> [$a['current'], $a['previous']]);

        $in = Facts::points($now, 'in');
        $out = Facts::points($now, 'out');

        return [
            'empty' => $in === 0 && $out === 0 && $packages($now) === 0 && $uses($now) === 0 && $sold($now, 'memberships') === 0 && $sold($now, 'packages') === 0,
            'currency' => null,
            'other_currencies' => [],
            'metrics' => [
                'loyalty_in' => Facts::metric($in, Facts::points($then, 'in')),
                'loyalty_out' => Facts::metric($out, Facts::points($then, 'out'), 'number', null),
                'package_redemptions' => Facts::metric($packages($now), $packages($then)),
                'membership_uses' => Facts::metric($uses($now), $uses($then)),
                'memberships_activated' => Facts::metric($sold($now, 'memberships'), $sold($then, 'memberships')),
                'packages_activated' => Facts::metric($sold($now, 'packages'), $sold($then, 'packages')),
            ],
            'series' => [
                'loyalty_in' => Facts::pair(
                    Facts::series($now['daily_points']['in'] ?? [], null, $reads->buckets),
                    Facts::aligned($then['daily_points']['in'] ?? [], null, $reads->previousBuckets, count($reads->buckets)),
                ),
                'loyalty_out' => Facts::pair(Facts::series($now['daily_points']['out'] ?? [], null, $reads->buckets), null),
            ],
            'parts' => [
                'movements' => $movements,
                'uses' => ['package_redemptions' => $packages($now), 'membership_uses' => $uses($now)],
                'sold' => [
                    'memberships' => ['current' => $sold($now, 'memberships'), 'previous' => $sold($then, 'memberships')],
                    'packages' => ['current' => $sold($now, 'packages'), 'previous' => $sold($then, 'packages')],
                ],
            ],
        ];
    }
}
