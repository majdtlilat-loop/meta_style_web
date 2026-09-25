<?php

declare(strict_types=1);

namespace App\Kernel\Time;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * An inclusive local-date range, with the period to compare it against.
 *
 * Built from a preset or a custom from/to in ONE timezone — the platform's for
 * Super Admin, the branch's for a center — and handed to readers as a
 * half-open UTC window `[start, end)`, the same convention the reports use.
 *
 * The comparison is always like for like:
 *
 *   today          → yesterday
 *   last 7 / 30    → the 7 / 30 days immediately before
 *   this month     → the SAME days of the previous month (1–23 Sep vs 1–23 Aug),
 *                    never a whole previous month against a partial current one
 *   custom         → the same number of days immediately before
 */
final class DateRange
{
    public const PRESETS = ['today', 'last_7_days', 'this_month', 'last_30_days', 'custom'];

    /** A dashboard is a summary; anything longer belongs in Reports. */
    public const MAX_DAYS = 366;

    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $timezone,
    ) {}

    public static function resolve(string $preset, ?string $from, ?string $to, string $timezone, ?CarbonImmutable $now = null): self
    {
        $today = ($now ?? CarbonImmutable::now($timezone))->setTimezone($timezone)->startOfDay();

        return match ($preset) {
            'today' => new self('today', $today, $today, $timezone),
            'last_7_days' => new self('last_7_days', $today->subDays(6), $today, $timezone),
            'last_30_days' => new self('last_30_days', $today->subDays(29), $today, $timezone),
            'custom' => self::custom($from, $to, $today, $timezone),
            default => new self('this_month', $today->startOfMonth(), $today, $timezone),
        };
    }

    private static function custom(?string $from, ?string $to, CarbonImmutable $today, string $timezone): self
    {
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', (string) $from, $timezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', (string) $to, $timezone);
        } catch (InvalidArgumentException) {
            $start = $end = false;
        }

        if (! $start instanceof CarbonImmutable || ! $end instanceof CarbonImmutable) {
            return new self('this_month', $today->startOfMonth(), $today, $timezone);
        }

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        if ($end->greaterThan($today)) {
            $end = $today;
        }

        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return new self('custom', $start, $end, $timezone);
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function previous(): self
    {
        if ($this->preset === 'this_month') {
            $start = $this->from->subMonthNoOverflow()->startOfMonth();
            $end = $start->addDays($this->days() - 1);
            $end = $end->greaterThan($start->endOfMonth()) ? $start->endOfMonth()->startOfDay() : $end;

            return new self('previous', $start, $end, $this->timezone);
        }

        $end = $this->from->subDay();

        return new self('previous', $end->subDays($this->days() - 1), $end, $this->timezone);
    }

    /** Inclusive start, in UTC. */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->startOfDay()->utc();
    }

    /** Exclusive end — the next local midnight — in UTC. */
    public function endUtc(): CarbonImmutable
    {
        return $this->to->addDay()->startOfDay()->utc();
    }

    /**
     * The buckets a time series over this range is drawn in: hours for a
     * single day, days up to two months, months beyond.
     *
     * @return list<array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function buckets(string $locale = 'en'): array
    {
        $buckets = [];

        if ($this->days() === 1) {
            for ($hour = 0; $hour < 24; $hour++) {
                $start = $this->from->setTime($hour, 0);
                $buckets[] = ['key' => $start->format('Y-m-d H'), 'label' => $start->format('H:00'), 'start' => $start->utc(), 'end' => $start->addHour()->utc()];
            }

            return $buckets;
        }

        if ($this->days() <= 62) {
            for ($day = $this->from; $day->lessThanOrEqualTo($this->to); $day = $day->addDay()) {
                $buckets[] = ['key' => $day->format('Y-m-d'), 'label' => $day->locale($locale)->isoFormat('D MMM'), 'start' => $day->utc(), 'end' => $day->addDay()->utc()];
            }

            return $buckets;
        }

        for ($month = $this->from->startOfMonth(); $month->lessThanOrEqualTo($this->to); $month = $month->addMonthNoOverflow()) {
            $start = $month->lessThan($this->from) ? $this->from : $month;
            $buckets[] = ['key' => $month->format('Y-m'), 'label' => $month->locale($locale)->isoFormat('MMM YYYY'), 'start' => $start->utc(), 'end' => $month->addMonthNoOverflow()->utc()];
        }

        return $buckets;
    }

    /**
     * The SQL expression grouping a UTC timestamp column into this range's
     * buckets, matching `buckets()` keys. Offsets are applied in PHP-computed
     * minutes so no database timezone tables are needed (MySQL and MariaDB).
     */
    public function bucketExpression(string $column): string
    {
        $offset = (int) round($this->from->utcOffset());
        $shifted = $offset === 0 ? $column : sprintf('DATE_ADD(%s, INTERVAL %d MINUTE)', $column, $offset);

        return match (true) {
            $this->days() === 1 => sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d %%H')", $shifted),
            $this->days() <= 62 => sprintf("DATE_FORMAT(%s, '%%Y-%%m-%%d')", $shifted),
            default => sprintf("DATE_FORMAT(%s, '%%Y-%%m')", $shifted),
        };
    }

    /** The comparison label the KPI cards use. */
    public function comparisonKey(): string
    {
        return match ($this->preset) {
            'today' => 'vs_yesterday',
            'this_month' => 'vs_last_month',
            default => 'vs_previous_period',
        };
    }
}
