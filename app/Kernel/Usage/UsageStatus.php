<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

/**
 * How close a metered resource is to its allowance.
 *
 * Four states rather than a raw percentage, because every surface that shows
 * usage has to make the same judgement — what colour, whether to warn, whether
 * to hand off — and a percentage computed independently in a dashboard, an
 * alert and a projection is three chances to disagree about what 85% means
 * (docs/26-USAGE-QUOTAS.md §10).
 *
 * An UNLIMITED resource is always {@see Normal}. It has no percentage at all:
 * the fraction has no denominator, and reporting 0% would suggest a limit
 * exists and is barely touched.
 */
enum UsageStatus: string
{
    case Normal = 'normal';
    case Warning = 'warning';
    case High = 'high';
    case Exhausted = 'exhausted';

    /**
     * Whether this state should stop NEW work on an enforced resource.
     *
     * Only `exhausted` does. `high` is a warning to a manager, never a refusal
     * to a customer — a center at 85% has paid for the remaining 15%.
     */
    public function blocks(): bool
    {
        return $this === self::Exhausted;
    }

    /**
     * The worst of the configured thresholds this percentage has reached.
     *
     * @param  list<array{percent: int, status: self}>  $thresholds
     */
    public static function forPercent(?int $percent, array $thresholds): self
    {
        if ($percent === null) {
            return self::Normal;
        }

        $status = self::Normal;

        foreach ($thresholds as $threshold) {
            if ($percent >= $threshold['percent']) {
                $status = $threshold['status'];
            }
        }

        return $status;
    }
}
