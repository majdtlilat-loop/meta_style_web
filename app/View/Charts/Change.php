<?php

declare(strict_types=1);

namespace App\View\Charts;

use App\View\Delta;

/**
 * Current against previous, with a SEMANTIC tone.
 *
 * A metric declares whether higher is better: bookings (true), cancellation
 * rate or average wait (false), or neither (null — e.g. a mix). The tone is
 * then good / bad / neutral from the metric's point of view, never "green
 * because it went up": fewer cancellations is good news.
 *
 * A relative percentage exists only when the previous value is non-zero.
 * From zero there is no honest percentage; the absolute difference is shown
 * instead.
 */
final class Change
{
    private function __construct(
        public readonly int|float $current,
        public readonly int|float|null $previous,
        /** up | down | flat | none (no previous value to compare with) */
        public readonly string $direction,
        /** Signed relative change in percent; null when previous is null or zero. */
        public readonly ?float $percent,
        /** Signed absolute difference; null when there is no previous value. */
        public readonly int|float|null $difference,
        /** good | bad | neutral */
        public readonly string $tone,
        public readonly ?bool $higherIsBetter,
    ) {}

    public static function between(int|float $current, int|float|null $previous, ?bool $higherIsBetter = true): self
    {
        if ($previous === null) {
            return new self($current, null, 'none', null, null, 'neutral', $higherIsBetter);
        }

        $difference = $current - $previous;

        if ((float) $difference === 0.0) {
            return new self($current, $previous, 'flat', (float) $previous === 0.0 ? null : 0.0, $difference, 'neutral', $higherIsBetter);
        }

        $direction = $difference > 0 ? 'up' : 'down';
        $percent = (float) $previous === 0.0 ? null : round($difference / abs($previous) * 100, 4);

        $tone = match (true) {
            $higherIsBetter === null => 'neutral',
            ($direction === 'up') === $higherIsBetter => 'good',
            default => 'bad',
        };

        return new self($current, $previous, $direction, $percent, $difference, $tone, $higherIsBetter);
    }

    /**
     * The short delta label: "+12.5%", "−4 pts" for a percent metric, "+3"
     * or "+25,000 IQD" from a zero base, "No change". Empty when there is
     * nothing to compare with.
     */
    public function label(?ValueFormat $format = null): string
    {
        $format ??= ValueFormat::make();

        return match (true) {
            $this->direction === 'none' => '',
            $this->direction === 'flat' => (string) __('ui.delta.no_change'),
            $format->kind === 'percent' => $format->difference((float) $this->difference),
            $this->percent !== null => ValueFormat::signedPercent($this->percent),
            default => $format->difference((float) $this->difference),
        };
    }

    /** Screen-reader words for the tone: the colour and the arrow are not enough. */
    public function toneLabel(): string
    {
        return match ($this->tone) {
            'good' => (string) __('manager_charts.tone.good'),
            'bad' => (string) __('manager_charts.tone.bad'),
            default => '',
        };
    }

    /**
     * The existing KPI delta, for `<x-ui.kpi :delta>` — same semantic
     * direction, positive / negative tone.
     */
    public function toDelta(bool $money = false): ?Delta
    {
        if ($this->previous === null) {
            return null;
        }

        return Delta::between($this->current, $this->previous, match ($this->higherIsBetter) {
            true => 'up',
            false => 'down',
            null => 'neutral',
        }, $money);
    }
}
