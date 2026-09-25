<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * Ring geometry for the donut and the radial meter, on a 100 × 100 viewBox
 * centred at (50, 50). Angles run clockwise from 12 o'clock; the stylesheet
 * mirrors the drawing in right-to-left layouts.
 */
final class Arc
{
    public const CENTER = 50.0;

    /**
     * Donut slices as SVG paths, separated by a surface gap of constant width
     * (`$gap` viewBox units — 1.25 ≈ 2px at the default size). A slice too
     * thin to show beside its gaps has a null path; it stays in the legend
     * and the data table.
     *
     * @param  list<int|float>  $values  non-negative
     * @return list<array{d: string|null, share: float, start: float, end: float}>
     */
    public static function donut(array $values, float $outer = 48.0, float $inner = 34.0, float $gap = 1.25): array
    {
        $values = array_map(static fn (int|float $value): float => max(0.0, (float) $value), $values);
        $total = array_sum($values);
        $slices = [];

        if ($total <= 0) {
            return array_map(static fn (): array => ['d' => null, 'share' => 0.0, 'start' => 0.0, 'end' => 0.0], $values);
        }

        $visible = count(array_filter($values, static fn (float $value): bool => $value > 0));
        $cursor = 0.0;

        foreach ($values as $value) {
            $share = $value / $total;
            $start = $cursor;
            $end = $cursor + $share * 2 * M_PI;
            $cursor = $end;

            if ($value <= 0) {
                $slices[] = ['d' => null, 'share' => 0.0, 'start' => $start, 'end' => $end];

                continue;
            }

            $d = $visible === 1
                ? self::ring($outer, $inner)
                : self::sector($start, $end, $outer, $inner, $gap);

            $slices[] = ['d' => $d, 'share' => $share, 'start' => $start, 'end' => $end];
        }

        return $slices;
    }

    /** A point on a circle at a fraction (0–1) of a full turn. */
    public static function point(float $fraction, float $radius): string
    {
        [$x, $y] = self::xy($fraction * 2 * M_PI, $radius);

        return self::n($x).' '.self::n($y);
    }

    /** A radial tick across the ring at a fraction of a full turn (a target marker). */
    public static function tick(float $fraction, float $from, float $to): string
    {
        $angle = $fraction * 2 * M_PI;
        [$x1, $y1] = self::xy($angle, $from);
        [$x2, $y2] = self::xy($angle, $to);

        return 'M'.self::n($x1).' '.self::n($y1).'L'.self::n($x2).' '.self::n($y2);
    }

    /** @return array{0: float, 1: float} */
    public static function xy(float $angle, float $radius): array
    {
        return [self::CENTER + $radius * sin($angle), self::CENTER - $radius * cos($angle)];
    }

    private static function sector(float $start, float $end, float $outer, float $inner, float $gap): ?string
    {
        $outerPad = ($gap / 2) / $outer;
        $innerPad = ($gap / 2) / $inner;

        $a0 = $start + $outerPad;
        $a1 = $end - $outerPad;
        $b0 = $start + $innerPad;
        $b1 = $end - $innerPad;

        if ($b1 <= $b0) {
            return null;
        }

        [$ox0, $oy0] = self::xy($a0, $outer);
        [$ox1, $oy1] = self::xy($a1, $outer);
        [$ix1, $iy1] = self::xy($b1, $inner);
        [$ix0, $iy0] = self::xy($b0, $inner);

        $largeOuter = $a1 - $a0 > M_PI ? 1 : 0;
        $largeInner = $b1 - $b0 > M_PI ? 1 : 0;
        $r = self::n($outer);
        $ri = self::n($inner);

        return 'M'.self::n($ox0).' '.self::n($oy0)
            .'A'.$r.' '.$r.' 0 '.$largeOuter.' 1 '.self::n($ox1).' '.self::n($oy1)
            .'L'.self::n($ix1).' '.self::n($iy1)
            .'A'.$ri.' '.$ri.' 0 '.$largeInner.' 0 '.self::n($ix0).' '.self::n($iy0)
            .'Z';
    }

    /** A full ring (one slice holds everything): two half-arcs out, two back in. */
    private static function ring(float $outer, float $inner): string
    {
        $c = self::n(self::CENTER);
        $top = self::n(self::CENTER - $outer);
        $bottom = self::n(self::CENTER + $outer);
        $topIn = self::n(self::CENTER - $inner);
        $bottomIn = self::n(self::CENTER + $inner);
        $r = self::n($outer);
        $ri = self::n($inner);

        return "M{$c} {$top}A{$r} {$r} 0 1 1 {$c} {$bottom}A{$r} {$r} 0 1 1 {$c} {$top}Z"
            ."M{$c} {$topIn}A{$ri} {$ri} 0 1 0 {$c} {$bottomIn}A{$ri} {$ri} 0 1 0 {$c} {$topIn}Z";
    }

    private static function n(float $value): string
    {
        $text = number_format($value, 3, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
