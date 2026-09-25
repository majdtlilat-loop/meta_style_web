<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use App\Kernel\Contact\PhoneNumber;
use App\View\Label;
use App\View\StatusTone;
use Carbon\CarbonImmutable;

/**
 * Dates, times and labels for the booking screens, in the viewer's language.
 *
 * Every date passed in is ALREADY branch-local (`Y-m-d` from the presenter or
 * the calendar's own state); nothing here converts a timezone, so nothing here
 * can quietly put a booking on the wrong day (docs/15-BOOKING.md §5).
 */
final class BookingFormat
{
    /** "Wednesday, 14 October 2026" */
    public static function dateLong(string $date): string
    {
        return self::parse($date)->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY');
    }

    /** "Wed 14 Oct" */
    public static function dateShort(string $date): string
    {
        return self::parse($date)->locale(app()->getLocale())->isoFormat('ddd D MMM');
    }

    /** "Wed" */
    public static function weekday(string $date): string
    {
        return self::parse($date)->locale(app()->getLocale())->isoFormat('ddd');
    }

    /** "14 Oct" */
    public static function dayMonth(string $date): string
    {
        return self::parse($date)->locale(app()->getLocale())->isoFormat('D MMM');
    }

    /** "14 – 20 Oct 2026", for a week or a list range. */
    public static function range(string $from, string $to): string
    {
        if ($from === $to) {
            return self::dateLong($from);
        }

        $locale = app()->getLocale();

        return self::parse($from)->locale($locale)->isoFormat('D MMM').' – '.self::parse($to)->locale($locale)->isoFormat('D MMM YYYY');
    }

    /** "1 h 15 min", "45 min" */
    public static function duration(int $minutes): string
    {
        $hours = intdiv(max(0, $minutes), 60);
        $rest = max(0, $minutes) % 60;

        if ($hours === 0) {
            return __('manager_booking.duration.minutes', ['minutes' => $rest]);
        }

        return $rest === 0
            ? __('manager_booking.duration.hours', ['hours' => $hours])
            : __('manager_booking.duration.hours_minutes', ['hours' => $hours, 'minutes' => $rest]);
    }

    /** An ISO instant shown as a branch-local "Wed 14 Oct · 10:30". */
    public static function instant(?string $iso, string $timezone): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return CarbonImmutable::parse($iso)->setTimezone($timezone)->locale(app()->getLocale())->isoFormat('ddd D MMM · HH:mm');
    }

    /**
     * Offered times grouped into morning, afternoon and evening by their
     * branch-local hour — how a person reads a list of forty times.
     *
     * @template TSlot of array{time: string}
     *
     * @param  list<TSlot>  $slots
     * @return list<array{key: string, label: string, slots: list<TSlot>}>
     */
    public static function periods(array $slots): array
    {
        $groups = ['morning' => [], 'afternoon' => [], 'evening' => []];

        foreach ($slots as $slot) {
            $hour = (int) substr($slot['time'], 0, 2);
            $groups[$hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening')][] = $slot;
        }

        $periods = [];

        foreach ($groups as $key => $group) {
            if ($group !== []) {
                $periods[] = ['key' => $key, 'label' => __('manager_booking.periods.'.$key), 'slots' => $group];
            }
        }

        return $periods;
    }

    /**
     * "+964 7509990004", as every other Manager page reads a number. A masked
     * number is shown exactly as the presenter masked it.
     */
    public static function phone(?string $phone, bool $masked): ?string
    {
        if ($phone === null || $masked) {
            return $phone;
        }

        return PhoneNumber::parse($phone)?->international() ?? $phone;
    }

    public static function status(string $status): string
    {
        return Label::for('appointment_status', $status);
    }

    public static function tone(string $status): string
    {
        return StatusTone::for($status);
    }

    public static function source(string $source): string
    {
        $key = 'manager_booking.source.'.$source;

        return __($key) === $key ? __('manager_booking.source.other') : __($key);
    }

    private static function parse(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: CarbonImmutable::now();
    }
}
