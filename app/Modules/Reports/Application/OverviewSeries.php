<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

/**
 * Lays the readers' branch-local counts onto a date range's buckets.
 *
 * Readers return `daily` (`Y-m-d`) and `hourly` (`Y-m-d H`) maps computed in
 * each branch's own timezone. A range of one day is drawn in hours, up to two
 * months in days, beyond that in months (DateRange::buckets) — so the Today
 * preset still has a real chart rather than a single bar.
 */
final class OverviewSeries
{
    /**
     * @param  array<string, int|float>  $daily
     * @param  array<string, int|float>  $hourly
     * @param  list<array{key: string}>  $buckets
     * @return list<int>
     */
    public static function onto(array $daily, array $hourly, array $buckets): array
    {
        if ($buckets === []) {
            return [];
        }

        $width = strlen($buckets[0]['key']);
        $source = $width === 13 ? $hourly : $daily;
        $totals = [];

        foreach ($source as $key => $value) {
            $bucket = substr((string) $key, 0, $width);
            $totals[$bucket] = ($totals[$bucket] ?? 0) + (int) $value;
        }

        return array_map(static fn (array $bucket): int => $totals[$bucket['key']] ?? 0, $buckets);
    }

    /**
     * The previous period's values, placed position by position under the
     * current buckets (day 1 against day 1), padded with zeros when the
     * previous period is shorter — "1–31 March vs 1–28 February".
     *
     * @param  array<string, int|float>  $daily
     * @param  array<string, int|float>  $hourly
     * @param  list<array{key: string}>  $previousBuckets
     * @return list<int>
     */
    public static function aligned(array $daily, array $hourly, array $previousBuckets, int $length): array
    {
        $values = self::onto($daily, $hourly, $previousBuckets);

        return array_slice(array_pad($values, $length, 0), 0, $length);
    }
}
