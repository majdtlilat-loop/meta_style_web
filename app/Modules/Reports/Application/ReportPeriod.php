<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Kernel\Time\DateRange;
use Carbon\CarbonImmutable;

/**
 * The period a Standard report covers, and the one it is compared with.
 *
 * Both are Kernel DateRanges in ONE timezone (the chosen branch's, else the
 * viewer's first branch's); each branch still reads its own local calendar
 * through ReportRequestFactory. The comparison is always like for like:
 *
 *   today        → yesterday
 *   last_7_days  → the 7 days immediately before
 *   this_month   → the SAME days of the previous month (DateRange::previous)
 *   last_month   → the whole month before it
 *   this_year    → the same days of the previous year (1 Jan → same date)
 *   custom       → the same number of days immediately before
 *
 * A malformed custom range falls back to this month, never to an error page.
 */
final readonly class ReportPeriod
{
    public const PRESETS = ['today', 'last_7_days', 'this_month', 'last_month', 'this_year', 'custom'];

    public const DEFAULT = 'this_month';

    private function __construct(
        public string $preset,
        public DateRange $current,
        public DateRange $previous,
    ) {}

    public static function resolve(string $preset, ?string $from, ?string $to, string $timezone, ?CarbonImmutable $now = null): self
    {
        $now = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
        $today = $now->startOfDay();

        switch ($preset) {
            case 'today':
            case 'last_7_days':
                $current = DateRange::resolve($preset, null, null, $timezone, $now);

                return new self($preset, $current, $current->previous());

            case 'last_month':
                $start = $today->startOfMonth()->subMonthNoOverflow();
                $before = $start->subMonthNoOverflow();

                return new self(
                    'last_month',
                    self::between($start, $start->endOfMonth(), $timezone, $now),
                    self::between($before, $before->endOfMonth(), $timezone, $now),
                );

            case 'this_year':
                $start = $today->startOfYear();

                return new self(
                    'this_year',
                    self::between($start, $today, $timezone, $now),
                    self::between($start->subYearNoOverflow(), $today->subYearNoOverflow(), $timezone, $now),
                );

            case 'custom':
                $current = DateRange::resolve('custom', $from, $to, $timezone, $now);

                // DateRange answers "this month" for dates it cannot read.
                return new self($current->preset === 'custom' ? 'custom' : self::DEFAULT, $current, $current->previous());

            default:
                $current = DateRange::resolve('this_month', null, null, $timezone, $now);

                return new self(self::DEFAULT, $current, $current->previous());
        }
    }

    /** How this period is compared, as a stable key the page translates. */
    public function comparisonKey(): string
    {
        return match ($this->preset) {
            'today' => 'yesterday',
            'last_7_days' => 'previous_7_days',
            'this_month' => 'same_days_last_month',
            'last_month' => 'previous_month',
            'this_year' => 'same_days_last_year',
            default => 'previous_period',
        };
    }

    private static function between(CarbonImmutable $from, CarbonImmutable $to, string $timezone, CarbonImmutable $now): DateRange
    {
        return DateRange::resolve('custom', $from->toDateString(), $to->toDateString(), $timezone, $now);
    }
}
