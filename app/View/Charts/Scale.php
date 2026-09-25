<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * A value axis with clean ticks (0 / 5 / 10 / 15, 0 / 250 / 500 …) so the
 * gridlines carry round numbers rather than the data maximum.
 */
final class Scale
{
    /**
     * @return array{max: float, ticks: list<float>}
     */
    public static function nice(float $max, int $intervals = 4): array
    {
        // Small whole counts (1, 2, 3 new centers) keep headroom: one bar at
        // full height reads as "a lot" when it is one.
        if ($max <= $intervals && floor($max) === $max) {
            return ['max' => (float) $intervals, 'ticks' => array_map('floatval', range(0, $intervals))];
        }

        $step = self::step($max / $intervals);

        // Whole-number data never gets a fractional tick.
        if ($step < 1 && floor($max) === $max) {
            $step = 1;
        }

        $top = $step * ceil($max / $step);
        $ticks = [];
        for ($value = 0.0; $value <= $top + ($step / 2); $value += $step) {
            $ticks[] = round($value, 6);
        }

        return ['max' => (float) $top, 'ticks' => $ticks];
    }

    /**
     * An axis for values that may go below zero (a net movement, a change).
     * Zero is always a tick, so the baseline is a gridline.
     *
     * @return array{min: float, max: float, ticks: list<float>}
     */
    public static function range(float $min, float $max, int $intervals = 4): array
    {
        $min = min(0.0, $min);
        $max = max(0.0, $max);

        if ($min === 0.0) {
            $nice = self::nice($max, $intervals);

            return ['min' => 0.0, 'max' => $nice['max'], 'ticks' => $nice['ticks']];
        }

        $step = self::step(($max - $min) / $intervals);
        $bottom = $step * floor($min / $step);
        $top = $max > 0 ? $step * ceil($max / $step) : 0.0;
        $ticks = [];
        for ($value = $bottom; $value <= $top + ($step / 2); $value += $step) {
            $ticks[] = round($value, 6) + 0.0;
        }

        return ['min' => (float) $bottom, 'max' => (float) $top, 'ticks' => $ticks];
    }

    /**
     * A percentage axis: 0–100 in quarters when the data fits, a nice axis
     * above that (a 140 % target attainment is real and must not be cut).
     *
     * @return array{max: float, ticks: list<float>}
     */
    public static function percent(float $max): array
    {
        if ($max <= 100 && $max > 40) {
            return ['max' => 100.0, 'ticks' => [0.0, 25.0, 50.0, 75.0, 100.0]];
        }

        return self::nice($max);
    }

    /** 1,284 · 12.9K · 4.2M — for axis ticks and tight labels. */
    public static function compact(float $value): string
    {
        $abs = abs($value);

        return match (true) {
            $abs >= 1_000_000_000 => rtrim(rtrim(number_format($value / 1_000_000_000, 1), '0'), '.').'B',
            $abs >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.').'M',
            $abs >= 10_000 => rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.').'K',
            default => number_format($value, $abs < 10 && floor($value) !== $value ? 1 : 0),
        };
    }

    /** Position of a value on an axis, 0–100 (percent of the plot height). */
    public static function position(float $value, float $min, float $max): float
    {
        if ($max <= $min) {
            return 0.0;
        }

        return round(max(0.0, min(100.0, ($value - $min) / ($max - $min) * 100)), 3);
    }

    private static function step(float $raw): float
    {
        if ($raw <= 0) {
            return 1.0;
        }

        $magnitude = 10 ** floor(log10($raw));
        $normalised = $raw / $magnitude;

        return (float) (match (true) {
            $normalised <= 1 => 1,
            $normalised <= 2 => 2,
            $normalised <= 2.5 => 2.5,
            $normalised <= 5 => 5,
            default => 10,
        } * $magnitude);
    }
}
