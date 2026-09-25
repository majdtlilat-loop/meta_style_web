<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Modules\Reviews\Application\ReviewEligibility;
use App\Modules\Reviews\Application\ReviewInvitations;
use App\Modules\Reviews\Application\ReviewsAccess;
use App\Modules\Reviews\Application\ReviewsAudit;
use App\Modules\Reviews\Domain\Events\ReviewInvitationIssued;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Gives one completed visit its review link — once.
 *
 *     BEGIN
 *       lock the visit's row
 *       it is completed, and something was actually performed
 *       it has no invitation yet
 *       mint: 256 random bits, digest stored, plaintext returned
 *       announce it
 *     COMMIT
 *
 * Called from the after-commit reaction to `JourneyCompleted` and from
 * reconciliation, so it must be safe to run any number of times. Two things
 * make it so: it checks first, and `review_invitations.service_journey_id` is
 * UNIQUE, so even a lost race ends with one row (docs/22-REVIEWS.md §4).
 *
 * ## It never issues without the entitlement, and never backdates one
 *
 * Unlike loyalty points, an invitation is not a debt the center already owes:
 * it is an invitation to speak, sent in the center's name. A center that no
 * longer owns `reviews` does not start sending them again because an old
 * reaction is being repaired — so this asks at ISSUE time, not at visit time.
 * A link the center already gave out is a different matter entirely and keeps
 * working until its own expiry (§18).
 */
final class IssueReviewInvitation
{
    public function __construct(
        private readonly ReviewsAccess $access,
        private readonly ReviewEligibility $eligibility,
        private readonly ReviewInvitations $invitations,
        private readonly ReviewsAudit $audit,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return array{0: ReviewInvitation, 1: string}|null the invitation and its
     *                                                    plaintext secret, or
     *                                                    null when there is
     *                                                    nothing to issue
     */
    public function forJourney(int $journeyId, ?CarbonImmutable $now = null): ?array
    {
        if (! $this->access->enabled()) {
            return null;
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var array{0: ReviewInvitation, 1: string}|null $minted */
        $minted = DB::connection('tenant')->transaction(function () use ($journeyId, $at): ?array {
            /** @var ServiceJourney|null $journey */
            $journey = ServiceJourney::query()->whereKey($journeyId)->lockForUpdate()->first();

            if (! $journey instanceof ServiceJourney || ! $this->eligibility->isReviewable($journey)) {
                return null;
            }

            if (ReviewInvitation::query()->where('service_journey_id', $journey->getKey())->exists()) {
                return null;
            }

            [$invitation, $secret] = $this->invitations->mint($journey, Actor::system('reviews'), $at);

            $this->audit->recordSystem('review.invitation_issued', ReviewInvitation::class, $invitation->uuid,
                after: ['expires_at' => $invitation->expires_at->toIso8601String()],
                meta: ['journey' => $journey->uuid, 'branch' => $invitation->branch_id],
            );

            // Identifiers only — never the secret (§5).
            $this->events->dispatch(new ReviewInvitationIssued(
                (int) $invitation->getKey(),
                (int) $journey->getKey(),
                (int) $invitation->branch_id,
                $invitation->customer_id === null ? null : (int) $invitation->customer_id,
            ));

            return [$invitation, $secret];
        });

        return $minted;
    }
}
