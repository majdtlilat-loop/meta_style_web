<?php

declare(strict_types=1);

namespace App\View\Reports\Standard;

use Carbon\CarbonImmutable;

/**
 * The customer-facing views: Customer activity, Benefit usage and Review
 * summary. Customers appear by name only — never a contact detail.
 */
final class CustomerLayouts
{
    /**
     * @param  array<string, mixed>  $facts
     * @param  callable(string): ?string  $customerUrl  a profile link, or null when the viewer may not open one
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function customers(array $facts, Cards $cards, string $locale, string $timezone, callable $customerUrl): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $visits = [1, 2, 3, 4, 5];

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('customers', $metrics),
                $cards->kpi('new', $metrics, $series),
                $cards->kpi('returning', $metrics),
                $cards->kpi('repeat_rate', $metrics),
                $cards->kpi('visits', $metrics),
                $cards->kpi('visit_frequency', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [$cards->line('new_customers', $series['new'] ?? null)],
                'distribution' => [
                    $cards->donut('customer_mix', CommerceLayouts::mix($parts['mix'])),
                    $cards->radial('repeat_rate', $metrics['repeat_rate']['current'], $metrics['repeat_rate']['previous'], 'accent', Cards::label('captions', 'returning')),
                    $cards->columns('visit_frequency', array_map(static fn (int $count): string => trans_choice('manager_reports.std.captions.visits', $count, ['count' => $count === 5 ? '5+' : (string) $count]), $visits), [
                        ['label' => Cards::label('captions', 'this_period'), 'values' => array_map(static fn (int $count): int => (int) ($parts['frequency'][$count] ?? 0), $visits)],
                        ['label' => $cards->comparison, 'values' => array_map(static fn (int $count): int => (int) ($parts['frequency_previous'][$count] ?? 0), $visits)],
                    ]),
                ],
                'details' => [
                    $cards->table('top_customers', [
                        ['key' => 'customer'], ['key' => 'visits', 'numeric' => true], ['key' => 'last_visit'], ['key' => 'customer_type'],
                    ], array_map(static function (array $row) use ($cards, $locale, $timezone, $customerUrl): array {
                        $last = CarbonImmutable::parse($row['last_visit'])->setTimezone($timezone);

                        return [
                            Cards::cell($row['name'], null, $customerUrl($row['uuid'])),
                            $cards->number($row['visits']),
                            Cards::cell($last->locale($locale)->isoFormat('D MMM YYYY'), $last->getTimestamp()),
                            Cards::cell(Cards::label('values.customer', $row['returning'] ? 'returning' : 'new')),
                        ];
                    }, $parts['top'])),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function benefits(array $facts, Cards $cards): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $movement = static fn (array $row): string => Cards::value('loyalty_kind', $row['kind']).' · '.Cards::value('direction', $row['direction']);

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('loyalty_in', $metrics, $series),
                $cards->kpi('loyalty_out', $metrics, $series),
                $cards->kpi('package_redemptions', $metrics),
                $cards->kpi('membership_uses', $metrics),
                $cards->kpi('memberships_activated', $metrics),
                $cards->kpi('packages_activated', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [$cards->line('loyalty_in', $series['loyalty_in'] ?? null)],
                'distribution' => [
                    $cards->donut('benefit_uses', [
                        ['label' => Cards::label('metrics', 'package_redemptions'), 'value' => $parts['uses']['package_redemptions']],
                        ['label' => Cards::label('metrics', 'membership_uses'), 'value' => $parts['uses']['membership_uses']],
                    ]),
                    $cards->grouped('benefits_sold', [Cards::label('metrics', 'memberships_activated'), Cards::label('metrics', 'packages_activated')], [
                        ['label' => Cards::label('captions', 'this_period'), 'values' => [$parts['sold']['memberships']['current'], $parts['sold']['packages']['current']]],
                        ['label' => $cards->comparison, 'values' => [$parts['sold']['memberships']['previous'], $parts['sold']['packages']['previous']]],
                    ]),
                ],
                'comparison' => [
                    $cards->ranked('points_by_movement', array_map(static fn (array $row): array => ['label' => $movement($row), 'value' => $row['current'], 'previous' => $row['previous']], $parts['movements']), better: null),
                ],
                'details' => [
                    $cards->table('movements', [
                        ['key' => 'movement'], ['key' => 'points', 'numeric' => true], ['key' => 'previous', 'numeric' => true], ['key' => 'change', 'numeric' => true],
                    ], array_map(static fn (array $row): array => [
                        Cards::cell($movement($row)),
                        $cards->number($row['current']),
                        $cards->number($row['previous']),
                        $cards->change($row['current'], $row['previous'], better: null),
                    ], $parts['movements'])),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function reviews(array $facts, Cards $cards): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $stars = [5, 4, 3, 2, 1];
        $rated = static fn (array $rows): array => self::rated($rows);
        $table = static fn (array $rows): array => array_map(static fn (array $row): array => [
            Cards::cell($row['name']),
            Cards::cell(number_format((float) $row['average'], 1), (float) $row['average']),
            $cards->number($row['count']),
        ], $rows);

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('reviews', $metrics, $series),
                $cards->kpi('average', $metrics),
                $cards->kpi('five_star_share', $metrics),
                $cards->kpi('low_ratings', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [$cards->line('reviews', $series['reviews'] ?? null)],
                'distribution' => [
                    $cards->ranked('rating_distribution', array_map(static fn (int $star): array => [
                        'label' => trans_choice('manager_reports.std.captions.stars', $star, ['count' => $star]),
                        'value' => (int) ($parts['distribution'][$star] ?? 0),
                        'previous' => (int) ($parts['distribution_previous'][$star] ?? 0),
                    ], $stars), share: true, sort: false, better: null, keepEmpty: true),
                ],
                'comparison' => [
                    $cards->ranked('employee_ratings', $rated($parts['employees']), limit: 8, additive: false),
                    $cards->ranked('service_ratings', $rated($parts['services']), limit: 8, additive: false),
                ],
                'details' => [
                    $cards->table('employee_ratings', [['key' => 'employee'], ['key' => 'rating', 'numeric' => true], ['key' => 'ratings', 'numeric' => true]], $table($parts['employees'])),
                    $cards->table('service_ratings', [['key' => 'service'], ['key' => 'rating', 'numeric' => true], ['key' => 'ratings', 'numeric' => true]], $table($parts['services'])),
                ],
            ]),
        ];
    }

    /**
     * Average ratings as ranked bars: best first, the better-sampled one
     * first on a tie, each with its number of ratings. Averages are never
     * added up, so the chart is built with `additive: false`.
     *
     * @param  list<array{name: string, average: float|int, count: int}>  $rows
     * @return list<array{label: string, value: float|int, meta: string}>
     */
    public static function rated(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [(float) $b['average'], (int) $b['count']] <=> [(float) $a['average'], (int) $a['count']]);

        return array_map(static fn (array $row): array => [
            'label' => $row['name'],
            'value' => $row['average'],
            'meta' => trans_choice('manager_reports.std.captions.ratings', (int) $row['count'], ['count' => number_format((int) $row['count'])]),
        ], $rows);
    }
}
