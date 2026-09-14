<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Events;

/**
 * The visit ended without finishing: the customer arrived, and left.
 *
 * Every open queue ticket for the visit stops being callable — calling a
 * number for somebody who has gone home is the display equivalent of a stale
 * record, and a host would keep pressing recall (docs/17-QUEUE.md §39).
 *
 * Aborting does NOT cancel the appointment. Journey never writes appointment
 * status; the flow that ends both calls the Booking lifecycle Action, and this
 * event says nothing about it.
 */
final readonly class JourneyAborted
{
    public function __construct(
        public int $journeyId,
        public int $branchId,
    ) {}
}
