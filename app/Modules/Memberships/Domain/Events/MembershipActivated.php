<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Events;

/**
 * A membership the customer paid for is now running.
 *
 * Raised by `ActivateMemberships` when it writes the term — which is itself
 * already after-commit work following a settled invoice (ADR-061). A listener
 * that tells the customer takes the same care: it schedules its write for after
 * this transaction commits, and is repaired by reconciliation if it is lost.
 */
final readonly class MembershipActivated
{
    public function __construct(
        public int $customerMembershipId,
        public int $customerId,
    ) {}
}
