<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Events;

/**
 * The visit is over: every service was performed or declined, and the visit
 * was closed as completed.
 *
 * Dispatched synchronously INSIDE the completion transaction, so a listener's
 * writes commit with the completion or not at all (the ADR-053 pattern). A
 * visit-based loyalty reward is the listener this exists for; it keys its
 * reward on the journey, so a repeated event can never award twice
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §5).
 *
 * Journey knows nothing about who listens. Completion still creates no sale,
 * no invoice and no payment.
 */
final readonly class JourneyCompleted
{
    public function __construct(
        public int $journeyId,
        public int $branchId,
    ) {}
}
