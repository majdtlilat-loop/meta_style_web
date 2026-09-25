<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * Small shared pieces of the chart builders: input normalisation, stable
 * element ids, tooltip rows.
 */
final class ChartData
{
    private static int $sequence = 0;

    /**
     * A per-render id for aria wiring. Sequential within one request, so a
     * Livewire re-render of the same page yields the same ids.
     */
    public static function id(string $prefix = 'chart'): string
    {
        self::$sequence++;

        return $prefix.'-'.self::$sequence;
    }

    /**
     * @param  mixed  $values  a list of numbers / nulls
     * @return list<int|float|null> exactly `$length` entries (null where missing or not numeric)
     */
    public static function values(mixed $values, int $length): array
    {
        $values = is_array($values) ? array_values($values) : [];
        $out = [];

        for ($i = 0; $i < $length; $i++) {
            $out[] = self::number($values[$i] ?? null);
        }

        return $out;
    }

    public static function number(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return is_float($value) && ! is_finite($value) ? null : $value;
        }

        return is_numeric($value) ? $value + 0 : null;
    }

    /** A non-negative magnitude (stacks, donuts and bars grow from zero). */
    public static function magnitude(mixed $value): int|float
    {
        $number = self::number($value);

        return $number === null || $number < 0 ? 0 : $number;
    }

    public static function text(mixed $value, string $fallback = ''): string
    {
        return is_scalar($value) ? trim((string) $value) : $fallback;
    }

    /**
     * @param  list<int|float|null>  ...$lists
     */
    public static function blank(array ...$lists): bool
    {
        foreach ($lists as $list) {
            foreach ($list as $value) {
                if ($value !== null && (float) $value !== 0.0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * One tooltip row (resources/js/platform/theme.js reads `series`,
     * `label`, `value` with textContent only).
     *
     * @return array{series: string, label: string, value: string}
     */
    public static function row(string $series, string $label, string $value): array
    {
        return ['series' => $series, 'label' => $label, 'value' => $value];
    }

    /** Whether a format adds up across buckets (a total row makes sense). */
    public static function additive(ValueFormat $format): bool
    {
        return in_array($format->kind, ['number', 'compact', 'money'], true);
    }
}
