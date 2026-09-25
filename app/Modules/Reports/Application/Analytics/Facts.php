<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

use App\Kernel\Money\Currency;
use App\Modules\Reports\Application\OverviewSeries;

/**
 * The small, shared arithmetic of the Standard report views — one
 * definition of a rate, a series, a lead currency and a comparison, so every
 * view (and the dashboard, through OverviewSeries) answers the same way.
 *
 * A metric's `unit` is what its number IS (number, money in minor units,
 * percentage points, seconds, minutes, decimal, rating); `better` says
 * whether higher is good news (true), bad news (false) or neither (null).
 */
final class Facts
{
    /** Booking outcomes: the bookings a rate can be taken over. */
    public const OUTCOMES = ['completed', 'cancelled', 'no_show'];

    /**
     * @return array{current: int|float|null, previous: int|float|null, unit: string, better: bool|null}
     */
    public static function metric(int|float|null $current, int|float|null $previous, string $unit = 'number', ?bool $better = true): array
    {
        return ['current' => $current, 'previous' => $previous, 'unit' => $unit, 'better' => $better];
    }

    /** A share in percentage points (one decimal), or null when there is nothing to divide by. */
    public static function rate(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    /** A mean, or null when there is nothing to average. */
    public static function mean(int|float $total, int|float $count, int $precision = 1): ?float
    {
        return $count > 0 ? round($total / $count, $precision) : null;
    }

    /**
     * Bookings that reached an outcome — the denominator of the completion,
     * cancellation and no-show rates, so the three always add up to 100 %.
     *
     * @param  array<string, int>  $status
     */
    public static function resolved(array $status): int
    {
        $total = 0;

        foreach (self::OUTCOMES as $outcome) {
            $total += (int) ($status[$outcome] ?? 0);
        }

        return $total;
    }

    /**
     * A reader's branch-local counts laid onto the period's buckets. A
     * source with no hourly detail (null) cannot draw a one-day period.
     *
     * @param  array<string, int|float>  $daily
     * @param  array<string, int|float>|null  $hourly
     * @param  list<array{key: string, label: string}>  $buckets
     * @return list<int>|null
     */
    public static function series(array $daily, ?array $hourly, array $buckets): ?array
    {
        if ($buckets === [] || ($hourly === null && strlen($buckets[0]['key']) === 13)) {
            return null;
        }

        return OverviewSeries::onto($daily, $hourly ?? [], $buckets);
    }

    /**
     * The previous period aligned position by position under the current
     * buckets (day 1 against day 1), padded or trimmed to their length.
     *
     * @param  array<string, int|float>  $daily
     * @param  array<string, int|float>|null  $hourly
     * @param  list<array{key: string, label: string}>  $previousBuckets
     * @return list<int>|null
     */
    public static function aligned(array $daily, ?array $hourly, array $previousBuckets, int $length): ?array
    {
        if ($previousBuckets === [] || ($hourly === null && strlen($previousBuckets[0]['key']) === 13)) {
            return null;
        }

        return OverviewSeries::aligned($daily, $hourly ?? [], $previousBuckets, $length);
    }

    /**
     * The currency with the most value across both periods — in practice the
     * center's one currency; the center's own when there is no money at all.
     *
     * @param  array<string, int>  ...$amounts
     */
    public static function leadCurrency(array ...$amounts): string
    {
        $totals = [];

        foreach ($amounts as $set) {
            foreach ($set as $code => $minor) {
                $totals[(string) $code] = ($totals[(string) $code] ?? 0) + abs((int) $minor);
            }
        }

        arsort($totals);
        $lead = array_key_first($totals);

        return is_string($lead) && $lead !== '' ? $lead : Currency::default()->value;
    }

    /**
     * Every OTHER currency with a non-zero amount — disclosed beside the
     * figures, never added into them.
     *
     * @param  array<string, int>  $amounts
     * @return list<array{currency: string, minor: int}>
     */
    public static function others(array $amounts, string $lead): array
    {
        $others = [];

        foreach ($amounts as $code => $minor) {
            if ((string) $code !== $lead && (int) $minor !== 0) {
                $others[] = ['currency' => (string) $code, 'minor' => (int) $minor];
            }
        }

        return $others;
    }

    /**
     * One dimension (services, employees, branches…) in both periods: every
     * member of either, with its current and previous value.
     *
     * @param  array<int|string, mixed>  $now
     * @param  array<int|string, mixed>  $then
     * @param  callable(mixed): (int|float)  $value
     * @param  callable(mixed): string  $name
     * @return list<array{id: int|string, name: string, current: int|float, previous: int|float, row: mixed, previous_row: mixed}>
     */
    public static function compare(array $now, array $then, callable $value, callable $name): array
    {
        $rows = [];

        foreach ($now as $id => $row) {
            $rows[$id] = ['id' => $id, 'name' => $name($row), 'current' => $value($row), 'previous' => 0, 'row' => $row, 'previous_row' => null];
        }

        foreach ($then as $id => $row) {
            $rows[$id] ??= ['id' => $id, 'name' => $name($row), 'current' => 0, 'previous' => 0, 'row' => null, 'previous_row' => null];
            $rows[$id]['previous'] = $value($row);
            $rows[$id]['previous_row'] = $row;
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int => [$b['current'], $b['previous']] <=> [$a['current'], $a['previous']]);

        return $rows;
    }

    /**
     * @param  array<int|string, int|float>  $values
     */
    public static function sum(array $values): int|float
    {
        return array_sum($values);
    }

    /**
     * @param  array<string, array<string, int>>  $methods
     * @return array<string, int>
     */
    public static function methods(array $methods, string $lead): array
    {
        $amounts = [];

        foreach ($methods as $method => $currencies) {
            if ((int) ($currencies[$lead] ?? 0) > 0) {
                $amounts[(string) $method] = (int) $currencies[$lead];
            }
        }

        arsort($amounts);

        return $amounts;
    }

    /**
     * Billed lines of one kind (services, products…) in the lead currency,
     * with the previous period's value for the same name.
     *
     * @param  list<array<string, mixed>>  $now
     * @param  list<array<string, mixed>>  $then
     * @return list<array{name: string, kind: string, current: int, previous: int, quantity: int}>
     */
    public static function items(array $now, array $then, string $lead, ?string $kind = null): array
    {
        $key = static fn (array $item): string => $item['kind'].'|'.$item['name'];
        $filter = static fn (array $item): bool => $item['currency'] === $lead && ($kind === null || $item['kind'] === $kind);
        $rows = [];

        foreach (array_filter($now, $filter) as $item) {
            $rows[$key($item)] = ['name' => (string) $item['name'], 'kind' => (string) $item['kind'], 'current' => (int) $item['total_minor'], 'previous' => 0, 'quantity' => (int) ($item['quantity'] ?? $item['count'])];
        }

        foreach (array_filter($then, $filter) as $item) {
            if (isset($rows[$key($item)])) {
                $rows[$key($item)]['previous'] = (int) $item['total_minor'];
            }
        }

        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['current'] > 0));
        usort($rows, static fn (array $a, array $b): int => $b['current'] <=> $a['current']);

        return $rows;
    }

    /** @param array<string, mixed> $benefits */
    public static function points(array $benefits, string $direction): int
    {
        $sum = 0;

        foreach ($benefits['loyalty'] ?? [] as $movement) {
            if ($movement['direction'] === $direction) {
                $sum += (int) $movement['points'];
            }
        }

        return $sum;
    }

    /**
     * A series in both periods (either may be absent) and its unit.
     *
     * @param  list<int>|null  $current
     * @param  list<int>|null  $previous
     * @return array{current: list<int>|null, previous: list<int>|null, unit: string}
     */
    public static function pair(?array $current, ?array $previous, string $unit = 'number'): array
    {
        return ['current' => $current, 'previous' => $previous, 'unit' => $unit];
    }

    /**
     * Bookings and billed value (lead currency) per branch in scope.
     *
     * @param  array<string, mixed>  $bookings
     * @param  array<string, mixed>  $bookingsBefore
     * @param  array<string, mixed>  $sales
     * @param  array<string, mixed>  $salesBefore
     * @return list<array{id: int, name: string, bookings: int, bookings_previous: int, billed: int, billed_previous: int, invoices: int}>
     */
    public static function branches(AnalyticsReads $reads, array $bookings, array $bookingsBefore, array $sales, array $salesBefore, string $lead): array
    {
        $rows = [];

        foreach ($reads->branchNames() as $id => $name) {
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'bookings' => (int) ($bookings['branches'][$id] ?? 0),
                'bookings_previous' => (int) ($bookingsBefore['branches'][$id] ?? 0),
                'billed' => (int) ($sales['branches'][$id][$lead] ?? 0),
                'billed_previous' => (int) ($salesBefore['branches'][$id][$lead] ?? 0),
                'invoices' => (int) ($sales['branch_invoices'][$id][$lead] ?? 0),
            ];
        }

        return $rows;
    }
}
