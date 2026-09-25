<?php

declare(strict_types=1);

namespace App\View\Reports\Standard;

use App\View\Label;

/**
 * The money-first views: Business overview, Sales & payments, Finance
 * movements. Each returns its KPI tiles and its sections in the page's
 * reading order — trends, distribution, comparison, details.
 */
final class CommerceLayouts
{
    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function overview(array $facts, Cards $cards): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('billed', $metrics, $series),
                $cards->kpi('net_collected', $metrics, $series),
                $cards->kpi('average_ticket', $metrics, $series),
                $cards->kpi('scheduled_bookings', $metrics, $series),
                $cards->kpi('completed_services', $metrics, $series),
                $cards->kpi('new_customers', $metrics, $series),
                $cards->kpi('returning_customers', $metrics, $series),
                $cards->kpi('cancellation_rate', $metrics, $series),
                $cards->kpi('no_show_rate', $metrics, $series),
                $cards->kpi('loyalty_points_in', $metrics, $series),
            ]),
            'sections' => Cards::sections([
                'trends' => [
                    $cards->line('billed', $series['billed'] ?? null, false),
                    $cards->line('scheduled_bookings', $series['scheduled_bookings'] ?? null, false),
                ],
                'distribution' => [
                    $cards->donut('booking_status', self::statuses($parts['booking_status']), 'status'),
                    $cards->radial('completion', $parts['completion']['current'], $parts['completion']['previous'], 'accent', Cards::label('captions', 'of_outcomes')),
                    $cards->donut('payment_methods', self::methods($parts['payment_methods']), unit: 'money'),
                    isset($parts['customer_mix']) ? $cards->donut('customer_mix', self::mix($parts['customer_mix'])) : null,
                ],
                'comparison' => [
                    $cards->ranked('billed_by_branch', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['billed'], 'previous' => $row['billed_previous']], $parts['branches']), 'money'),
                    $cards->ranked('top_services_billed', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['current'], 'previous' => $row['previous']], $parts['top_services']), 'money'),
                    $cards->donut('categories', self::categories($parts['categories']), unit: 'money'),
                ],
                'details' => [
                    $cards->table('branch_summary', [
                        ['key' => 'branch'], ['key' => 'bookings', 'numeric' => true], ['key' => 'billed', 'numeric' => true],
                        ['key' => 'invoices', 'numeric' => true], ['key' => 'average_ticket', 'numeric' => true], ['key' => 'change', 'numeric' => true],
                    ], array_map(static fn (array $row): array => [
                        Cards::cell($row['name']),
                        $cards->number($row['bookings']),
                        $cards->number($row['billed'], 'money'),
                        $cards->number($row['invoices']),
                        $cards->number($row['invoices'] > 0 ? (int) round($row['billed'] / $row['invoices']) : null, 'money'),
                        $cards->change($row['billed'], $row['billed_previous'], 'money'),
                    ], $parts['branches'])),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function sales(array $facts, Cards $cards): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $billed = array_sum(array_map(static fn (array $row): int => $row['current'], $parts['items']));
        $collected = array_sum($parts['payment_methods']);

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('billed', $metrics, $series),
                $cards->kpi('net_collected', $metrics, $series),
                $cards->kpi('average_ticket', $metrics),
                $cards->kpi('invoices', $metrics),
                $cards->kpi('collected', $metrics),
                $cards->kpi('refunded', $metrics),
                $cards->kpi('outstanding', $metrics),
                $cards->kpi('voided', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [
                    $cards->line('billed', $series['billed'] ?? null, false),
                    $cards->line('net_collected', $series['net_collected'] ?? null, false),
                ],
                'distribution' => [
                    $cards->donut('payment_methods', self::methods($parts['payment_methods']), unit: 'money'),
                    $cards->donut('categories', self::categories($parts['categories']), unit: 'money'),
                    $cards->donut('kinds', array_map(static fn (string $kind, int $value): array => ['label' => Cards::value('kind', $kind), 'value' => $value], array_keys($parts['kinds']), array_values($parts['kinds'])), unit: 'money'),
                ],
                'comparison' => [
                    $cards->ranked('top_items', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['current'], 'previous' => $row['previous'], 'meta' => Cards::value('kind', $row['kind'])], $parts['items']), 'money'),
                    $cards->ranked('billed_by_branch', array_map(static fn (array $row): array => ['label' => $row['name'], 'value' => $row['billed'], 'previous' => $row['billed_previous']], $parts['branches']), 'money'),
                ],
                'details' => [
                    $cards->table('items', [
                        ['key' => 'item'], ['key' => 'kind'], ['key' => 'quantity', 'numeric' => true], ['key' => 'billed', 'numeric' => true],
                        ['key' => 'average_price', 'numeric' => true], ['key' => 'share', 'numeric' => true], ['key' => 'change', 'numeric' => true],
                    ], array_map(static fn (array $row): array => [
                        Cards::cell($row['name']),
                        Cards::cell(Cards::value('kind', $row['kind'])),
                        $cards->number($row['quantity']),
                        $cards->number($row['current'], 'money'),
                        $cards->number($row['quantity'] > 0 ? (int) round($row['current'] / $row['quantity']) : null, 'money'),
                        $cards->number($billed > 0 ? round($row['current'] / $billed * 100, 1) : null, 'percent'),
                        $cards->change($row['current'], $row['previous'], 'money'),
                    ], $parts['items'])),
                    $cards->table('payment_methods', [
                        ['key' => 'method'], ['key' => 'collected', 'numeric' => true], ['key' => 'share', 'numeric' => true], ['key' => 'change', 'numeric' => true],
                    ], array_map(static fn (string $method, int $value): array => [
                        Cards::cell(self::method($method)),
                        $cards->number($value, 'money'),
                        $cards->number($collected > 0 ? round($value / $collected * 100, 1) : null, 'percent'),
                        $cards->change($value, (int) ($parts['payment_methods_previous'][$method] ?? 0), 'money'),
                    ], array_keys($parts['payment_methods']), array_values($parts['payment_methods']))),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>}
     */
    public function finance(array $facts, Cards $cards): array
    {
        [$metrics, $series, $parts] = [$facts['metrics'], $facts['series'], $facts['parts']];
        $kinds = array_keys($parts['kinds']);

        return [
            'kpis' => Cards::kpis([
                $cards->kpi('net_movement', $metrics, $series),
                $cards->kpi('collections', $metrics),
                $cards->kpi('refunds', $metrics),
                $cards->kpi('expenses', $metrics),
                $cards->kpi('expense_reversals', $metrics),
                $cards->kpi('reconciliation_variance', $metrics),
            ]),
            'sections' => Cards::sections([
                'trends' => [$cards->line('net_movement', $series['net_movement'] ?? null)],
                'comparison' => [
                    $cards->grouped('movements_by_kind', array_map(static fn (string $kind): string => Cards::value('finance_kind', $kind), $kinds), [
                        ['label' => Cards::label('captions', 'this_period'), 'values' => array_map(static fn (array $row): int => $row['current'], array_values($parts['kinds']))],
                        ['label' => $cards->comparison, 'values' => array_map(static fn (array $row): int => $row['previous'], array_values($parts['kinds']))],
                    ], 'money'),
                ],
                'details' => [
                    $cards->table('finance_methods', [
                        ['key' => 'method'], ['key' => 'net_movement', 'numeric' => true], ['key' => 'previous', 'numeric' => true], ['key' => 'change', 'numeric' => true],
                    ], array_map(static fn (array $row): array => [
                        Cards::cell(self::method($row['method'])),
                        $cards->number($row['current'], 'money'),
                        $cards->number($row['previous'], 'money'),
                        $cards->change($row['current'], $row['previous'], 'money'),
                    ], $parts['methods'])),
                ],
            ]),
        ];
    }

    /**
     * @param  array<string, int>  $status
     * @return list<array{label: string, value: int, status: string}>
     */
    public static function statuses(array $status): array
    {
        $items = [];

        foreach ($status as $code => $count) {
            $items[] = ['label' => Label::for('appointment_status', (string) $code), 'value' => (int) $count, 'status' => (string) $code];
        }

        return $items;
    }

    /**
     * @param  array<string, int>  $methods
     * @return list<array{label: string, value: int}>
     */
    private static function methods(array $methods): array
    {
        return array_map(static fn (string $method, int $value): array => ['label' => self::method($method), 'value' => $value], array_keys($methods), array_values($methods));
    }

    private static function method(string $method): string
    {
        return $method === '' ? (string) __('manager_reports.values.method_other') : Label::for('pos_payment_method', $method);
    }

    /**
     * @param  array{new: int, returning: int}  $mix
     * @return list<array{label: string, value: int, color: int}>
     */
    public static function mix(array $mix): array
    {
        return [
            ['label' => Cards::label('values.customer', 'new'), 'value' => $mix['new'], 'color' => 1],
            ['label' => Cards::label('values.customer', 'returning'), 'value' => $mix['returning'], 'color' => 2],
        ];
    }

    /**
     * Billed value by service category; lines without one (products, custom
     * lines, memberships and packages, uncategorised services) by their kind.
     *
     * @param  list<array{category: string|null, kind: string, total_minor: int}>  $rows
     * @return list<array{label: string, value: int}>
     */
    private static function categories(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $label = $row['category'] ?? Cards::value('uncategorised', $row['kind']);
            $grouped[$label] = ($grouped[$label] ?? 0) + (int) $row['total_minor'];
        }

        arsort($grouped);

        return array_map(static fn (string $label, int $value): array => ['label' => $label, 'value' => $value], array_keys($grouped), array_values($grouped));
    }
}
