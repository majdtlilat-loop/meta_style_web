<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Reviews\Application\ReviewEligibility;
use App\Modules\Reviews\Application\ReviewInvitations;
use App\Modules\Reviews\Application\ReviewsAccess;
use App\Modules\Reviews\Application\ReviewsAudit;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The desk's two buttons on a visit's review link: reissue it, or kill it.
 *
 * ## Reissue is how you get the link at all
 *
 * A stored secret cannot be read back — only its digest exists — so "show me
 * this customer's review link" is not a question the database can answer. The
 * desk issues a NEW one, which is also what it wants when a link was sent to
 * the wrong number or photographed on a counter. Revoke then create, under the
 * visit's lock, so a visit never has two working links and never zero for
 * longer than the transaction (docs/22-REVIEWS.md §5, docs/18-SALES.md §20).
 *
 * Reissuing is NEW activity and needs `reviews`. Revoking is not: a center that
 * lost the entitlement must still be able to kill a leaked link (§18).
 *
 * Neither secret is audited, nor its digest. An audit row is readable by more
 * people than a customer's review link should be.
 */
final class ManageReviewInvitation
{
    public function __construct(
        private readonly ReviewsAccess $access,
        private readonly ReviewEligibility $eligibility,
        private readonly ReviewInvitations $invitations,
        private readonly ReviewsAudit $audit,
    ) {}

    /**
     * Issues a fresh link for a completed visit, revoking whatever it had.
     *
     * @return string the new plaintext secret; compose the URL with
     *                {@see ReviewInvitations::url()} and never store it
     *
     * @throws ReviewFailed
     * @throws AuthorizationException
     */
    public function reissue(ServiceJourney $journey, User $actingUser, ?CarbonImmutable $now = null): string
    {
        $this->access->ensure($actingUser, Permission::ReviewManage, $journey->branchId(), 'You may not issue review links.');

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var string $secret */
        $secret = DB::connection('tenant')->transaction(function () use ($journey, $actingUser, $at): string {
            /** @var ServiceJourney|null $locked */
            $locked = ServiceJourney::query()->whereKey($journey->getKey())->lockForUpdate()->first();

            if (! $locked instanceof ServiceJourney || ! $this->eligibility->isReviewable($locked)) {
                throw ReviewFailed::notEligible('That visit cannot be reviewed.');
            }

            /** @var ReviewInvitation|null $current */
            $current = ReviewInvitation::query()->where('service_journey_id', $locked->getKey())->lockForUpdate()->first();

            if ($current instanceof ReviewInvitation) {
                if ($current->status === InvitationStatus::Used) {
                    // The customer has already had their say. A second link
                    // would be a second review for one visit (§14).
                    throw ReviewFailed::alreadySubmitted();
                }

                /*
                 * The row is deleted rather than kept as `revoked`: its own
                 * visit column is UNIQUE, so one visit can hold one invitation,
                 * and a tombstone would block every future link for a customer
                 * whose first one went astray. The audit row is the history.
                 */
                $current->delete();
            }

            [$invitation, $secret] = $this->invitations->mint($locked, Actor::staff($actingUser), $at);

            $this->audit->record('review.invitation_reissued', $actingUser, ReviewInvitation::class, $invitation->uuid,
                after: ['expires_at' => $invitation->expires_at->toIso8601String()],
                meta: ['journey' => $locked->uuid, 'branch' => $invitation->branch_id, 'replaced' => $current instanceof ReviewInvitation],
                severity: AuditSeverity::Notice,
            );

            return $secret;
        });

        return $secret;
    }

    /**
     * Stops a link working, leaving the visit with none.
     *
     * @throws ReviewFailed
     * @throws AuthorizationException
     */
    public function revoke(ReviewInvitation $invitation, User $actingUser, ?CarbonImmutable $now = null): void
    {
        $this->access->authorize($actingUser, Permission::ReviewManage, (int) $invitation->branch_id, 'You may not revoke review links.');

        $at = ($now ?? CarbonImmutable::now())->utc();

        DB::connection('tenant')->transaction(function () use ($invitation, $actingUser, $at): void {
            /** @var ReviewInvitation|null $locked */
            $locked = ReviewInvitation::query()->whereKey($invitation->getKey())->lockForUpdate()->first();

            if (! $locked instanceof ReviewInvitation) {
                throw ReviewFailed::invalidToken();
            }

            if ($locked->status === InvitationStatus::Used) {
                throw ReviewFailed::alreadySubmitted();
            }

            if ($locked->status === InvitationStatus::Revoked) {
                return;
            }

            $locked->forceFill(['status' => InvitationStatus::Revoked, 'revoked_at' => $at])->save();

            $this->audit->record('review.invitation_revoked', $actingUser, ReviewInvitation::class, $locked->uuid,
                meta: ['branch' => $locked->branch_id],
                severity: AuditSeverity::Notice,
            );
        });
    }
}
