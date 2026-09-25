<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * Folds a long tail into one "Other" entry, so a chart never needs a ninth
 * colour or an eighth donut slice.
 *
 * The folded entries are kept as `members` on the Other entry: a chart's data
 * table still lists every one of them, so folding hides nothing.
 */
final class Fold
{
    /**
     * Keep the `$keep` largest items (by `value`) and fold the rest.
     *
     * @param  list<array<string, mixed>>  $items  each with `label` and `value` (and optionally `previous`)
     * @param  bool  $sort  largest first; false keeps the given order and folds the tail
     * @return list<array<string, mixed>> each item plus `other` (bool); Other also carries `members` and `previous` when every folded member had one
     */
    public static function top(array $items, int $keep, string $otherLabel, bool $sort = true): array
    {
        $keep = max(1, $keep);

        if ($sort) {
            $indexed = array_map(null, array_keys($items), $items);
            usort($indexed, static function (array $a, array $b): int {
                $order = self::num($b[1]['value'] ?? 0) <=> self::num($a[1]['value'] ?? 0);

                return $order !== 0 ? $order : $a[0] <=> $b[0];
            });
            $items = array_map(static fn (array $pair): array => $pair[1], $indexed);
        }

        if (count($items) <= $keep) {
            return array_map(static fn (array $item): array => $item + ['other' => false], $items);
        }

        $kept = array_map(static fn (array $item): array => $item + ['other' => false], array_slice($items, 0, $keep));
        $rest = array_slice($items, $keep);

        $value = 0;
        $previous = 0;
        $hasPrevious = true;
        foreach ($rest as $member) {
            $value += self::num($member['value'] ?? 0);
            if (! array_key_exists('previous', $member) || $member['previous'] === null) {
                $hasPrevious = false;
            } else {
                $previous += self::num($member['previous']);
            }
        }

        $other = ['label' => $otherLabel, 'value' => $value, 'other' => true, 'members' => $rest];
        if ($hasPrevious) {
            $other['previous'] = $previous;
        }

        $kept[] = $other;

        return $kept;
    }

    /**
     * Series over buckets: keep `$keep` series (largest total first unless
     * `$sort` is false) and sum the rest bucket by bucket into Other.
     *
     * @param  list<array<string, mixed>>  $series  each with `label` and `values` (list<int|float|null>)
     * @return list<array<string, mixed>> each series plus `other` (bool); Other carries `members`
     */
    public static function series(array $series, int $keep, string $otherLabel, bool $sort = false): array
    {
        $keep = max(1, $keep);

        if (count($series) <= $keep) {
            return array_map(static fn (array $s): array => $s + ['other' => false], $series);
        }

        if ($sort) {
            $indexed = array_map(null, array_keys($series), $series);
            usort($indexed, static function (array $a, array $b): int {
                $order = self::total(self::values($b[1])) <=> self::total(self::values($a[1]));

                return $order !== 0 ? $order : $a[0] <=> $b[0];
            });
            $series = array_map(static fn (array $pair): array => $pair[1], $indexed);
        }

        $kept = array_map(static fn (array $s): array => $s + ['other' => false], array_slice($series, 0, $keep));
        $rest = array_slice($series, $keep);
        $length = max(array_map(static fn (array $s): int => count(self::values($s)), $series));

        $values = [];
        for ($i = 0; $i < $length; $i++) {
            $sum = 0;
            foreach ($rest as $member) {
                $sum += self::num(self::values($member)[$i] ?? 0);
            }
            $values[] = $sum;
        }

        $kept[] = ['label' => $otherLabel, 'values' => $values, 'other' => true, 'members' => $rest];

        return $kept;
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private static function total(array $values): float
    {
        return (float) array_sum(array_map(static fn (mixed $value): float => self::num($value), $values));
    }

    /**
     * @param  array<string, mixed>  $series
     * @return array<int, mixed>
     */
    private static function values(array $series): array
    {
        return is_array($series['values'] ?? null) ? array_values($series['values']) : [];
    }

    private static function num(mixed $value): float|int
    {
        return is_int($value) || is_float($value) ? $value : (is_numeric($value) ? $value + 0 : 0);
    }
}
