<?php

declare(strict_types=1);

namespace App\Livewire\Center\PosFinance;

use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * An instant as the branch's wall clock reads it, in the viewer's language.
 *
 * Everything money-related is stored in UTC and happened somewhere: a payment
 * taken at 00:30 in Baghdad belongs to that Baghdad day, not to the UTC day
 * before it. Components format through here while they build the arrays a
 * view prints, so no template ever parses a date (docs/20-FINANCE.md §42).
 */
final class BranchTime
{
    public const DATE = 'D MMM YYYY';

    public const DATE_SHORT = 'D MMM';

    public const TIME = 'HH:mm';

    public const DATETIME = 'D MMM, HH:mm';

    public const DATETIME_FULL = 'D MMM YYYY, HH:mm';

    public static function label(?string $iso, string $timezone, string $pattern = self::DATETIME): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        try {
            $instant = CarbonImmutable::parse($iso)->utc();
        } catch (Throwable) {
            return '—';
        }

        return BranchClock::toLocal($instant, self::zone($timezone))
            ->locale(app()->getLocale())
            ->isoFormat($pattern);
    }

    /** The branch's calendar date today, `Y-m-d`. */
    public static function today(string $timezone): string
    {
        return BranchClock::localDate(CarbonImmutable::now()->utc(), self::zone($timezone));
    }

    /** A `Y-m-d` date as the viewer reads it, e.g. "3 Sep 2026". */
    public static function day(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return '—';
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        return $parsed instanceof CarbonImmutable ? $parsed->locale(app()->getLocale())->isoFormat(self::DATE) : '—';
    }

    public static function zoneOf(?Branch $branch): string
    {
        return $branch instanceof Branch ? self::zone($branch->timezone) : 'UTC';
    }

    private static function zone(?string $timezone): string
    {
        return is_string($timezone) && $timezone !== '' && BranchClock::isKnownTimezone($timezone) ? $timezone : 'UTC';
    }
}
