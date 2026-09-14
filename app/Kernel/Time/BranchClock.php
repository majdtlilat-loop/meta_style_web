<?php

declare(strict_types=1);

namespace App\Kernel\Time;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;

/**
 * The only place branch-local wall-clock time becomes absolute time.
 *
 * Meta Style stores UTC and computes in the BRANCH's timezone — not the
 * server's, not the tenant's, not the signed-in user's
 * (docs/10-API-FOUNDATION.md §8). A center with a branch in Baghdad and a
 * branch in Istanbul is a supported case, and "today's bookings" is a
 * per-branch question.
 *
 * ## Why this class exists rather than a `Carbon::parse($tz)` at each call site
 *
 * Two local times are not what they appear to be, and PHP resolves both
 * SILENTLY:
 *
 *  - **Non-existent.** On a spring-forward day the clock jumps 02:00 → 03:00,
 *    so 02:30 never happens. PHP moves it to 03:30 and says nothing. A booking
 *    engine that accepted it would offer a slot at a time that does not exist,
 *    and then place the appointment an hour from where the calendar showed it.
 *    {@see toUtc()} returns null instead.
 *  - **Ambiguous.** On a fall-back day 01:30 happens twice. PHP picks the first
 *    occurrence. That is a defensible answer and it is applied consistently,
 *    but it has to be a decision rather than an accident, so it is recorded
 *    here and tested.
 *
 * ## Wall clock first, arithmetic second
 *
 * Everything below builds the intended WALL CLOCK as a string and then asks the
 * timezone to resolve it. The obvious alternative — take local midnight and add
 * minutes — cannot detect a gap at all: PHP's `addMinutes()` moves the absolute
 * instant, so midnight plus 150 minutes is a perfectly real 03:30 rather than
 * the 02:30 that was asked for. The bug looks exactly like success.
 *
 * Iraq does not observe DST, so nothing in the launch market exercises any of
 * this. That is precisely the reason to handle it here: the bug would first
 * appear in a market nobody was testing (Phase 6 §37).
 */
final class BranchClock
{
    /**
     * Converts a branch-local date and minute-of-day to absolute UTC.
     *
     * Returns NULL when that wall-clock time does not exist in the zone. The
     * caller decides what that means: for slot generation it means "not a
     * slot", and for a submitted booking it means the request is refused.
     *
     * @param  string  $date  `Y-m-d`, in the branch's own calendar
     * @param  int  $minutes  minutes from local midnight; may exceed 1440 for
     *                        an interval that runs past midnight
     */
    public static function toUtc(string $date, int $minutes, string $timezone): ?CarbonImmutable
    {
        $zone = self::zone($timezone);
        $wall = self::wallClock($date, $minutes);

        $local = CarbonImmutable::parse($wall, $zone);

        // The existence test. If this wall clock is real, PHP reproduces it
        // exactly; if it fell in a DST gap, PHP has moved it and the strings
        // differ.
        if ($local->format('Y-m-d H:i') !== $wall) {
            return null;
        }

        return $local->utc();
    }

    /**
     * The same conversion, but never null: a non-existent local time resolves
     * to the first instant that does exist.
     *
     * For the BOUNDARIES of a day's opening interval. A branch whose stored
     * 02:00 opening falls in a spring-forward gap is still open that morning —
     * it opens when the clock reaches the far side — and dropping the whole day
     * would be worse than moving the boundary by an hour.
     */
    public static function toUtcOrShift(string $date, int $minutes, string $timezone): CarbonImmutable
    {
        // PHP's own forward shift across the gap is exactly the behaviour
        // wanted here, so the result is taken unconditionally.
        return CarbonImmutable::parse(
            self::wallClock($date, $minutes),
            self::zone($timezone),
        )->utc();
    }

    /**
     * The branch-local calendar date of an absolute instant.
     */
    public static function localDate(CarbonImmutable $instant, string $timezone): string
    {
        return $instant->setTimezone(self::zone($timezone))->format('Y-m-d');
    }

    public static function toLocal(CarbonImmutable $instant, string $timezone): CarbonImmutable
    {
        return $instant->setTimezone(self::zone($timezone));
    }

    /**
     * `0` = Sunday … `6` = Saturday, matching `branch_working_hours`.
     */
    public static function localDayOfWeek(string $date, string $timezone): int
    {
        // Midday, not midnight: on a day whose midnight falls in a DST gap the
        // shifted instant could land on the following date.
        return (int) CarbonImmutable::parse($date.' 12:00', self::zone($timezone))->dayOfWeek;
    }

    /**
     * The wall clock `$minutes` after local midnight on `$date`, as `Y-m-d H:i`.
     *
     * Day arithmetic is done in UTC on the DATE alone, which has no timezone
     * meaning and therefore no transition to fall into. Only the final string
     * is handed to the branch's zone to resolve.
     */
    private static function wallClock(string $date, int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $remainder = $minutes % 1440;

        if ($remainder < 0) {
            $remainder += 1440;
            $days--;
        }

        return sprintf(
            '%s %02d:%02d',
            CarbonImmutable::parse($date, 'UTC')->addDays($days)->format('Y-m-d'),
            intdiv($remainder, 60),
            $remainder % 60,
        );
    }

    /**
     * A timezone Meta Style will actually compute in.
     *
     * An unknown identifier falls back to UTC rather than throwing. A branch
     * with a corrupt timezone string is a data problem, and taking the whole
     * booking surface down for it would turn a bad field into an outage — but
     * it must not pass unnoticed either, so it is a distinct, obviously-wrong
     * answer rather than the server's local guess.
     */
    private static function zone(string $timezone): DateTimeZone
    {
        try {
            return new DateTimeZone($timezone);
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }

    public static function isKnownTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Exception) {
            return false;
        }
    }
}
