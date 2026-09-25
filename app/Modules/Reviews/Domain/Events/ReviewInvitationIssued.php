<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Events;

/**
 * A completed visit now has a review invitation.
 *
 * Carries identifiers only, and deliberately NOT the token: the plaintext
 * exists in one place, the URL the minting call returned, and putting it on an
 * event would hand it to every listener and then to whatever they store
 * (docs/22-REVIEWS.md §5).
 *
 * Notifications listens to this to put a review invitation in a signed-in
 * customer's inbox. Reviews never learns who listened, and never depends on a
 * listener succeeding.
 */
final readonly class ReviewInvitationIssued
{
    public function __construct(
        public int $invitationId,
        public int $serviceJourneyId,
        public int $branchId,
        public ?int $customerId,
    ) {}
}
