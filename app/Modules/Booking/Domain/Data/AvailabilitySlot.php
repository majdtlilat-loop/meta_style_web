<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Kernel\Time\TimeWindow;
use Carbon\CarbonImmutable;

/**
 * One bookable start time, with the employees the engine would assign.
 *
 * ADVISORY, NOT A RESERVATION. A slot is what was true when it was computed;
 * two customers can be looking at the same one. Nothing here holds anything —
 * the only authority is the re-check inside the booking transaction, which is
 * why a slot returned by this engine can still be refused a second later
 * (docs/13-ROADMAP.md Phase 6 §9).
 *
 * `assignments` maps line position → employee id, so a client that shows "with
 * Ahmed" beside a time is showing the person who would actually be booked
 * rather than a guess.
 */
final readonly class AvailabilitySlot
{
    /**
     * @param  array<int, int|null>  $assignments  line position => employee id
     */
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $localDate,
        public string $localTime,
        public array $assignments,
    ) {}

    public function window(): TimeWindow
    {
        return new TimeWindow($this->startsAt, $this->endsAt);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // ISO-8601 with offset, per docs/10-API-FOUNDATION.md §8. A client
            // that only wants the wall clock gets it below rather than having
            // to parse and convert.
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'date' => $this->localDate,
            'time' => $this->localTime,
        ];
    }
}
