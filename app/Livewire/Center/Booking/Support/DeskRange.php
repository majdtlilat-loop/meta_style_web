<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * The branch-local dates a desk view covers.
 *
 * Day: one date. Week: seven days from SATURDAY — hardcoding Monday would put
 * the weekend in the middle of the grid for every center in the launch market.
 * List: `date` to `until`, a week by default and never more than the 31 days
 * CalendarQuery allows (docs/15-BOOKING.md §12). Dates only; no timezone is
 * converted here.
 */
final readonly class DeskRange
{
    public const MAX_LIST_DAYS = 31;

    public string $from;

    public string $to;

    public function __construct(public string $view, public string $date, public string $until = '')
    {
        $start = CarbonImmutable::parse($date);

        [$from, $to] = match ($view) {
            'week' => [$start->subDays(($start->dayOfWeek + 1) % 7), $start->subDays(($start->dayOfWeek + 1) % 7)->addDays(6)],
            'list' => [$start, $start->addDays(self::listDays($date, $until) - 1)],
            default => [$start, $start],
        };

        $this->from = $from->format('Y-m-d');
        $this->to = $to->format('Y-m-d');
    }

    /** How far "previous" and "next" move. */
    public function step(): int
    {
        return match ($this->view) {
            'week' => 7,
            'list' => self::listDays($this->date, $this->until),
            default => 1,
        };
    }

    /**
     * @return list<string>
     */
    public function days(): array
    {
        $days = [];
        $cursor = CarbonImmutable::parse($this->from);

        while ($cursor->format('Y-m-d') <= $this->to) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    public function heading(): string
    {
        return $this->view === 'day' ? BookingFormat::dateLong($this->from) : BookingFormat::range($this->from, $this->to);
    }

    /** The list's concrete last day; empty for the other views. */
    public static function listEnd(string $view, string $date, string $until): string
    {
        if ($view !== 'list') {
            return $until;
        }

        return CarbonImmutable::parse($date)->addDays(self::listDays($date, $until) - 1)->format('Y-m-d');
    }

    public static function listDays(string $date, string $until): int
    {
        if ($until === '' || $until < $date) {
            return 7;
        }

        $days = (int) CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($until)) + 1;

        return max(1, min(self::MAX_LIST_DAYS, $days));
    }

    /** A real `Y-m-d` calendar date, or null — never a date PHP "corrected". */
    public static function validDate(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
