<?php

declare(strict_types=1);

namespace App\View;

/**
 * The change between two periods, as a KPI card shows it.
 *
 * A percentage only when it is meaningful: from a zero base the change is
 * shown as an absolute number (or "new" for money), never as an infinite or
 * invented percentage. The tone follows the METRIC, not the arrow — fewer
 * cancellations is good news, so `better: 'down'` makes a fall positive.
 */
final class Delta
{
    private function __construct(
        public readonly string $text,
        public readonly string $tone,
        public readonly string $direction,
    ) {}

    /**
     * @param  'up'|'down'|'neutral'  $better
     */
    public static function between(int|float $current, int|float $previous, string $better = 'up', bool $money = false): self
    {
        if ($current == $previous) {
            return new self((string) __('ui.delta.no_change'), 'neutral', 'flat');
        }

        $direction = $current > $previous ? 'up' : 'down';
        $sign = $direction === 'up' ? '+' : '−';

        if ($previous == 0) {
            $text = $money ? (string) __('ui.delta.new') : $sign.number_format(abs($current - $previous));
        } else {
            $percent = abs(($current - $previous) / abs($previous) * 100);
            $text = $sign.number_format($percent, $percent >= 100 ? 0 : 1).'%';
        }

        $tone = match (true) {
            $better === 'neutral' => 'neutral',
            $direction === $better => 'positive',
            default => 'negative',
        };

        return new self($text, $tone, $direction);
    }
}
