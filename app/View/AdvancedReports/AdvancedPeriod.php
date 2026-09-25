<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The Advanced Reports period: a preset (or a custom range) in the branch
 * timezone, and the period it is compared with.
 *
 *   previous   like for like, as everywhere in the Manager (DateRange):
 *              a day → the day before; last N days → the N days before;
 *              this month / quarter / year → the SAME days of the previous
 *              month / quarter / year; last month → the month before;
 *              custom → the same number of days immediately before.
 *   last_year  the same local dates one year earlier.
 *
 * Never longer than 366 days (the interactive reporting limit) and never
 * past today.
 */
final readonly class AdvancedPeriod
{
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'last_90_days', 'this_month', 'last_month', 'this_quarter', 'this_year', 'custom'];

    public const COMPARISONS = ['previous', 'last_year'];

    public const MAX_DAYS = 366;

    private function __construct(
        public string $preset,
        public string $comparison,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public CarbonImmutable $compareFrom,
        public CarbonImmutable $compareTo,
        public string $timezone,
    ) {}

    public static function resolve(string $preset, ?string $from, ?string $to, string $comparison, string $timezone, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();
        $preset = in_array($preset, self::PRESETS, true) ? $preset : 'this_month';
        $comparison = in_array($comparison, self::COMPARISONS, true) ? $comparison : 'previous';

        [$start, $end] = match ($preset) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'last_30_days' => [$today->subDays(29), $today],
            'last_90_days' => [$today->subDays(89), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$today->firstOfQuarter(), $today],
            'this_year' => [$today->startOfYear(), $today],
            'custom' => self::custom($from, $to, $today, $timezone),
            default => [$today->startOfMonth(), $today],
        };

        if ($preset === 'custom' && $start === null) {
            $preset = 'this_month';
            [$start, $end] = [$today->startOfMonth(), $today];
        }

        /** @var CarbonImmutable $start */
        /** @var CarbonImmutable $end */
        [$compareFrom, $compareTo] = $comparison === 'last_year'
            ? [$start->subYearNoOverflow(), $end->subYearNoOverflow()]
            : self::previous($preset, $start, $end);

        return new self($preset, $comparison, $start, $end, $compareFrom, $compareTo, $timezone);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function isCustom(): bool
    {
        return $this->preset === 'custom';
    }

    /**
     * The time buckets a series over this period is drawn in: hours for one
     * day, days up to two months, weeks up to about half a year, months beyond.
     *
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    public function buckets(string $locale): array
    {
        return self::bucketsFor($this->from, $this->to, $locale);
    }

    /**
     * The comparison period's buckets, laid under the current ones position
     * by position (day 1 against day 1).
     *
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    public function comparisonBuckets(string $locale): array
    {
        return self::bucketsFor($this->compareFrom, $this->compareTo, $locale, $this->days());
    }

    public function label(string $locale): string
    {
        return self::describe($this->from, $this->to, $locale);
    }

    public function comparisonLabel(string $locale): string
    {
        return self::describe($this->compareFrom, $this->compareTo, $locale);
    }

    /**
     * @return list<array{key: string, label: string, from: string, to: string}>
     */
    private static function bucketsFor(CarbonImmutable $from, CarbonImmutable $to, string $locale, ?int $lengthOf = null): array
    {
        $days = $lengthOf ?? (int) $from->diffInDays($to) + 1;
        $buckets = [];

        if ($days === 1) {
            for ($hour = 0; $hour < 24; $hour++) {
                $start = $from->setTime($hour, 0);
                $buckets[] = ['key' => $start->format('Y-m-d H'), 'label' => $start->format('H:00'), 'from' => $from->toDateString(), 'to' => $from->toDateString()];
            }

            return $buckets;
        }

        $step = match (true) {
            $days <= 62 => 1,
            $days <= 190 => 7,
            default => 0,
        };

        if ($step === 0) {
            for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($to); $month = $month->addMonthNoOverflow()) {
                $start = $month->lessThan($from) ? $from : $month;
                $end = $month->endOfMonth()->startOfDay()->greaterThan($to) ? $to : $month->endOfMonth()->startOfDay();
                $buckets[] = ['key' => $month->format('Y-m'), 'label' => $month->locale($locale)->isoFormat('MMM YYYY'), 'from' => $start->toDateString(), 'to' => $end->toDateString()];
            }

            return $buckets;
        }

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDays($step)) {
            $end = $day->addDays($step - 1)->greaterThan($to) ? $to : $day->addDays($step - 1);
            $buckets[] = ['key' => $day->format('Y-m-d'), 'label' => $day->locale($locale)->isoFormat('D MMM'), 'from' => $day->toDateString(), 'to' => $end->toDateString()];
        }

        return $buckets;
    }

    private static function describe(CarbonImmutable $from, CarbonImmutable $to, string $locale): string
    {
        return $from->equalTo($to)
            ? $from->locale($locale)->isoFormat('D MMM YYYY')
            : $from->locale($locale)->isoFormat($from->year === $to->year ? 'D MMM' : 'D MMM YYYY').' – '.$to->locale($locale)->isoFormat('D MMM YYYY');
    }

    /** @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null} */
    private static function custom(?string $from, ?string $to, CarbonImmutable $today, string $timezone): array
    {
        try {
            $start = is_string($from) && $from !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone) : false;
            $end = is_string($to) && $to !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone) : false;
        } catch (InvalidArgumentException) {
            // A hand-edited URL: fall back to the default period.
            return [null, null];
        }

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
            return [null, null];
        }

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        $end = $end->greaterThan($today) ? $today : $end;
        $start = $start->greaterThan($end) ? $end : $start;

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return [$start, $end];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function previous(string $preset, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;

        $aligned = static function (CarbonImmutable $start, CarbonImmutable $periodEnd) use ($days): array {
            $end = $start->addDays($days - 1);

            return [$start, $end->greaterThan($periodEnd) ? $periodEnd : $end];
        };

        return match ($preset) {
            'this_month' => $aligned($from->subMonthNoOverflow()->startOfMonth(), $from->subMonthNoOverflow()->endOfMonth()->startOfDay()),
            'last_month' => [$from->subMonthNoOverflow()->startOfMonth(), $from->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => $aligned($from->subMonthsNoOverflow(3)->firstOfQuarter(), $from->subMonthsNoOverflow(3)->lastOfQuarter()->startOfDay()),
            'this_year' => $aligned($from->subYearNoOverflow()->startOfYear(), $from->subYearNoOverflow()->endOfYear()->startOfDay()),
            default => [$from->subDays($days), $from->subDay()],
        };
    }
}
