<?php

declare(strict_types=1);

namespace App\View\Charts;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Places a previous period's values under the current period's buckets, so a
 * line chart can draw "this period vs the one before" on one time axis.
 *
 * Two ways, both explicit about what a missing bucket is — `null` (no line
 * drawn), never an invented zero:
 *
 *   byPosition  day 1 against day 1 (the report domain compares with the equal
 *               preceding range, "1–31 March vs 1–28 February")
 *   byOffset    each current key shifted back by a fixed interval
 *               ("2026-09-12" − 1 year → "2025-09-12")
 */
final class PeriodAlign
{
    /**
     * @param  list<int|float|null>  $previous  the previous period in bucket order
     * @return list<int|float|null> exactly `$length` values
     */
    public static function byPosition(array $previous, int $length): array
    {
        return array_map(static fn (int $i): int|float|null => $previous[$i] ?? null, $length > 0 ? range(0, $length - 1) : []);
    }

    /**
     * @param  list<string>  $keys  current bucket keys, e.g. '2026-09-12', '2026-09-24 14', '2026-09'
     * @param  array<string, int|float|null>  $previous  previous values keyed the same way
     * @param  string  $shift  a relative date string: '1 year', '7 days', '1 month'
     * @param  string  $format  the key format ('Y-m-d', 'Y-m-d H', 'Y-m')
     * @return list<int|float|null>
     */
    public static function byOffset(array $keys, array $previous, string $shift, string $format = 'Y-m-d'): array
    {
        $aligned = [];

        foreach ($keys as $key) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $key, 'UTC');
                $then = $date instanceof CarbonImmutable ? $date->modify('-'.ltrim($shift, '+-'))->format($format) : null;
            } catch (Throwable) {
                $then = null;
            }

            $aligned[] = $then !== null ? ($previous[$then] ?? null) : null;
        }

        return $aligned;
    }

    /**
     * The previous period's bucket labels, position by position, for the
     * tooltip ("12 Sep · previous 12 Aug").
     *
     * @param  array<mixed>  $previousBuckets  strings or ['label' => string] (untyped Blade input)
     * @return list<string|null>
     */
    public static function labels(array $previousBuckets, int $length): array
    {
        $labels = array_map(static fn (string $label): ?string => $label === '' ? null : $label, Axis::labels($previousBuckets));

        return array_map(static fn (int $i): ?string => $labels[$i] ?? null, $length > 0 ? range(0, $length - 1) : []);
    }
}
