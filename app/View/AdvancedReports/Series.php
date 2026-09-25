<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use App\Kernel\Money\Currency;
use App\View\Charts\ValueFormat;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Shapes the readers' branch-local maps for the Advanced charts.
 *
 * Readers return `daily` (`Y-m-d`) and `hourly` (`Y-m-d H`) maps already in
 * each branch's own timezone; these helpers only lay them onto the period's
 * buckets or onto a day-of-week × hour grid. Nothing is estimated: a bucket
 * with no facts is 0, a day of the week that never occurred is absent.
 */
final class Series
{
    /**
     * @param  array<array-key, mixed>  $daily
     * @param  array<array-key, mixed>  $hourly
     * @param  list<array{key: string, label: string, from: string, to: string}>  $buckets
     * @return list<int|float>
     */
    public static function onto(array $daily, array $hourly, array $buckets): array
    {
        if ($buckets === []) {
            return [];
        }

        if (strlen($buckets[0]['key']) === 13) {
            return array_map(static fn (array $bucket): int|float => self::num($hourly[$bucket['key']] ?? 0), $buckets);
        }

        $values = array_fill(0, count($buckets), 0);

        foreach ($daily as $date => $value) {
            $date = (string) $date;

            foreach ($buckets as $i => $bucket) {
                if ($date >= $bucket['from'] && $date <= $bucket['to']) {
                    $values[$i] += self::num($value);

                    break;
                }
            }
        }

        return $values;
    }

    /**
     * The queue reader's daily LIST ({date, tickets, …}) as a map of one field.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int|float>
     */
    public static function column(array $rows, string $field): array
    {
        $map = [];

        foreach ($rows as $row) {
            if (isset($row['date']) && is_numeric($row[$field] ?? null)) {
                $map[(string) $row['date']] = self::num($row[$field]);
            }
        }

        return $map;
    }

    /**
     * A day-of-week × hour grid from an hourly (`Y-m-d H`) map, weeks starting
     * on Saturday, hours trimmed to the first and last hour with any activity.
     *
     * @param  array<array-key, mixed>  $hourly
     * @return array{rows: list<string>, columns: list<string>, values: list<list<int>>}|null null when there is nothing to draw
     */
    public static function heatmap(array $hourly, string $locale): ?array
    {
        $grid = [];
        $hours = [];

        foreach ($hourly as $key => $value) {
            try {
                $moment = CarbonImmutable::createFromFormat('!Y-m-d H', (string) $key, 'UTC');
            } catch (InvalidArgumentException) {
                continue;
            }

            if (! $moment instanceof CarbonImmutable || self::num($value) <= 0) {
                continue;
            }

            $day = ($moment->dayOfWeek + 1) % 7; // Saturday = 0 … Friday = 6
            $hour = (int) $moment->format('G');
            $grid[$day][$hour] = ($grid[$day][$hour] ?? 0) + (int) self::num($value);
            $hours[] = $hour;
        }

        if ($hours === []) {
            return null;
        }

        $columns = range(min($hours), max($hours));
        $saturday = CarbonImmutable::parse('2026-01-03'); // a Saturday
        $rows = [];
        $values = [];

        for ($day = 0; $day < 7; $day++) {
            $rows[] = $saturday->addDays($day)->locale($locale)->isoFormat('ddd');
            $values[] = array_map(static fn (int $hour): int => $grid[$day][$hour] ?? 0, $columns);
        }

        return [
            'rows' => $rows,
            'columns' => array_map(static fn (int $hour): string => sprintf('%02d', $hour), $columns),
            'values' => $values,
        ];
    }

    /**
     * The currency the money cards speak: the one with the most billed and
     * collected value across both periods, else the center's own. Money in
     * any other currency is disclosed beside it, never added in.
     *
     * @param  list<array<array-key, mixed>>  $amountMaps  currency => minor
     */
    public static function leadCurrency(array $amountMaps): string
    {
        $totals = [];

        foreach ($amountMaps as $map) {
            foreach ($map as $code => $amount) {
                $totals[(string) $code] = ($totals[(string) $code] ?? 0) + abs(self::num($amount));
            }
        }

        arsort($totals);
        $lead = array_key_first($totals);

        return is_string($lead) && $lead !== '' ? $lead : Currency::default()->value;
    }

    /**
     * "1,200 USD" lines for every currency other than the lead one.
     *
     * @param  array<array-key, mixed>  $amounts  currency => minor
     * @return list<string>
     */
    public static function otherCurrencies(array $amounts, string $lead): array
    {
        $lines = [];

        foreach ($amounts as $code => $amount) {
            if ((string) $code !== $lead && (float) self::num($amount) !== 0.0) {
                $lines[] = ValueFormat::make('money', (string) $code)->full(self::num($amount));
            }
        }

        return $lines;
    }

    /**
     * The disclosure sentences for money the lead-currency figures leave
     * out: billed and net collected in any other currency, each listed as
     * its own amount, never converted and never added in.
     *
     * @param  array<array-key, mixed>  $billed  currency => minor
     * @param  array<array-key, mixed>  $collected  currency => minor (net)
     * @return list<string>
     */
    public static function currencyNotes(array $billed, array $collected, string $lead): array
    {
        $notes = [];

        if (($amounts = self::otherCurrencies($billed, $lead)) !== []) {
            $notes[] = (string) __('manager_advanced.values.other_currencies', ['amounts' => implode(' · ', $amounts)]);
        }

        if (($amounts = self::otherCurrencies($collected, $lead)) !== []) {
            $notes[] = (string) __('manager_advanced.values.other_collected', ['amounts' => implode(' · ', $amounts)]);
        }

        return $notes;
    }

    /** A rate in percentage points, or null when there is no denominator. */
    public static function rate(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    public static function num(mixed $value): int|float
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : 0);
    }

    /** @param list<int|float|null> $values */
    public static function blank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && (float) $value !== 0.0) {
                return false;
            }
        }

        return true;
    }
}
