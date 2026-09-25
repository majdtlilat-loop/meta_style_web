<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * Category / time axis labels. A label is never clipped: instead of shrinking
 * text, labels are thinned — every Nth on a wide card, and `thin` ones hide
 * again on a narrow card (a container query in charts.css).
 */
final class Axis
{
    /**
     * @param  array<mixed>  $buckets  strings or ['label' => string, …] (untyped Blade input)
     * @return list<string>
     */
    public static function labels(array $buckets): array
    {
        return array_map(static function (mixed $bucket): string {
            if (is_scalar($bucket)) {
                return (string) $bucket;
            }

            $label = is_array($bucket) ? ($bucket['label'] ?? '') : '';

            return is_scalar($label) ? (string) $label : '';
        }, array_values($buckets));
    }

    /**
     * Which labels to print: about `$target` across the axis, the first always.
     *
     * @param  list<string>  $labels
     * @return list<array{label: string, show: bool, thin: bool}>
     */
    public static function ticks(array $labels, int $target = 8): array
    {
        $count = count($labels);
        $every = $count > $target ? (int) ceil($count / $target) : 1;

        return array_map(static fn (string $label, int $i): array => [
            'label' => $label,
            'show' => $i % $every === 0,
            // Shown on a wide card, hidden on a narrow one.
            'thin' => $i % $every === 0 && $count > 6 && intdiv($i, $every) % 2 === 1,
        ], $labels, array_keys($labels));
    }
}
