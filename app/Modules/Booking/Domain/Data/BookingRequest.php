<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Kernel\Time\BranchClock;
use Carbon\CarbonImmutable;

/**
 * "Book this."
 *
 * `startsAt` is an ABSOLUTE INSTANT, already in UTC. Adapters parse ISO-8601
 * with offset (docs/10-API-FOUNDATION.md §8) or echo back the `starts_at` a
 * slot gave them; either way the ambiguity of local time is resolved before the
 * request exists. That matters because a wall-clock string can be
 * non-existent — 02:30 on a spring-forward morning — and a booking request is
 * the wrong place to discover it. Slot generation, which does work in local
 * time, refuses those in {@see BranchClock}.
 *
 * Only the FIRST service's start is given. The rest follow it, back to back, in
 * the order the lines were sent (Phase 6 §4).
 */
final readonly class BookingRequest
{
    /**
     * @param  list<BookingLine>  $lines
     */
    public function __construct(
        public string $branchUuid,
        public array $lines,
        public CarbonImmutable $startsAt,
        public CustomerRef $customer,
        public ?string $customerNote = null,
    ) {}
}
