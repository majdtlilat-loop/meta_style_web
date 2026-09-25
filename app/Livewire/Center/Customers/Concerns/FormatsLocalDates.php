<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers\Concerns;

use App\Modules\Branches\Domain\Models\Branch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Dates for the CRM screens, in the viewer's language and the right clock.
 *
 * Something that happened at a BRANCH (a visit, a sale) is shown on that
 * branch's clock — pass its timezone. Something center-wide (when a customer
 * was added, their last sign-in) uses the main branch's clock, the one the
 * center runs its day by. Never the server's (docs/10 §9).
 */
trait FormatsLocalDates
{
    private ?string $centerZone = null;

    protected function centerTimezone(): string
    {
        if ($this->centerZone === null) {
            $zone = Branch::query()->orderByDesc('is_main')->orderBy('id')->value('timezone');
            $this->centerZone = is_string($zone) && $zone !== '' ? $zone : (string) config('app.timezone', 'UTC');
        }

        return $this->centerZone;
    }

    /**
     * "20 Sep 2026" on the given (or the center's) clock. A calendar date that
     * is not an instant — a date of birth — passes `$instant = false`.
     */
    protected function localDate(CarbonInterface|string|null $at, bool $instant = true, ?string $timezone = null): ?string
    {
        $moment = $this->moment($at, $instant, $timezone);

        return $moment?->isoFormat('D MMM YYYY');
    }

    /** "20 Sep 2026, 14:05" on the given (or the center's) clock. */
    protected function localDateTime(CarbonInterface|string|null $at, ?string $timezone = null): ?string
    {
        return $this->moment($at, true, $timezone)?->isoFormat('D MMM YYYY, HH:mm');
    }

    /** "3 days ago", in the viewer's language. */
    protected function relative(CarbonInterface|string $at): string
    {
        return CarbonImmutable::parse($at)->locale(app()->getLocale())->diffForHumans();
    }

    private function moment(CarbonInterface|string|null $at, bool $instant, ?string $timezone): ?CarbonImmutable
    {
        if ($at === null || $at === '') {
            return null;
        }

        $moment = $at instanceof CarbonInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse($at);

        if ($instant) {
            $moment = $moment->setTimezone($timezone !== null && $timezone !== '' ? $timezone : $this->centerTimezone());
        }

        return $moment->locale(app()->getLocale());
    }
}
