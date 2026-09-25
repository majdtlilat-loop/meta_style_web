<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Reviews\Application\ReviewsAccess;
use App\Modules\Reviews\Application\ReviewsAudit;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Hide, unhide or flag a review — and nothing else.
 *
 * ## Moderation changes visibility, never content
 *
 * `overall_rating` and `public_comment` are what the customer wrote, and the
 * model refuses to let anything change them. A center that could edit a review
 * would be publishing its own opinion under a customer's name, and the only
 * honest thing a center can do with feedback it dislikes is take it down and
 * say why (docs/22-REVIEWS.md §15).
 *
 * So every moderation writes three things beside the status: WHO, WHEN and, for
 * anything that removes a review from the center's public picture, WHY. All of
 * it is audited (§47).
 *
 * ## Hiding is not deleting
 *
 * A hidden review keeps every word and simply stops counting towards the
 * averages. It is still readable by staff, because "what did we hide, and on
 * what grounds" is a question a center has to be able to answer about itself.
 * Nothing in Phase 12 deletes a submitted review.
 *
 * ## Not gated on `reviews`
 *
 * Moderating what has already been collected is a control over history, not a
 * new commercial operation. A center that loses the entitlement stops ISSUING
 * invitations; it must not also lose the ability to take down a review it is
 * responsible for (§18).
 */
final class ModerateReview
{
    public function __construct(
        private readonly ReviewsAccess $access,
        private readonly ReviewsAudit $audit,
    ) {}

    /**
     * @throws ReviewFailed
     * @throws AuthorizationException
     */
    public function hide(Review $review, User $actingUser, string $reason, ?CarbonImmutable $now = null): Review
    {
        $reason = trim($reason);

        if ($reason === '') {
            // A review taken out of the center's ratings without a stated
            // reason is indistinguishable from one taken down because somebody
            // did not like it.
            throw ReviewFailed::policy(__('manager_customers.errors.review_hide_reason'));
        }

        return $this->apply($review, $actingUser, ReviewStatus::Hidden, $reason, $now);
    }

    /**
     * @throws ReviewFailed
     * @throws AuthorizationException
     */
    public function unhide(Review $review, User $actingUser, ?CarbonImmutable $now = null): Review
    {
        return $this->apply($review, $actingUser, ReviewStatus::Submitted, null, $now);
    }

    /**
     * Marks a review for internal attention. It STILL COUNTS: flagging is a
     * note to colleagues, not a verdict on the customer, and an average that
     * moved the moment somebody clicked "look at this" would measure staff
     * attention rather than service (§§8, 24).
     *
     * @throws ReviewFailed
     * @throws AuthorizationException
     */
    public function flag(Review $review, User $actingUser, ?string $reason = null, ?CarbonImmutable $now = null): Review
    {
        $reason = $reason === null ? null : trim($reason);

        return $this->apply($review, $actingUser, ReviewStatus::Flagged, $reason === '' ? null : $reason, $now);
    }

    /**
     * @throws ReviewFailed
     * @throws AuthorizationException
     */
    private function apply(Review $review, User $actingUser, ReviewStatus $target, ?string $reason, ?CarbonImmutable $now): Review
    {
        $this->access->authorize($actingUser, Permission::ReviewManage, (int) $review->branch_id, __('manager_customers.errors.may_not_moderate'));

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var Review $moderated */
        $moderated = DB::connection('tenant')->transaction(function () use ($review, $actingUser, $target, $reason, $at): Review {
            /** @var Review|null $locked */
            $locked = Review::query()->whereKey($review->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Review) {
                throw ReviewFailed::policy(__('manager_customers.errors.review_missing'));
            }

            if ($locked->status === $target) {
                // Already there. Not an error, and not a second audit row.
                return $locked;
            }

            $before = ['status' => $locked->status->value];

            $locked->forceFill([
                'status' => $target,
                'moderated_at' => $at,
                'moderated_by_id' => $actingUser->uuid,
                'moderated_by_label' => $actingUser->name,
                'moderation_reason' => $reason,
            ])->save();

            $this->audit->record('review.'.$target->value, $actingUser, Review::class, $locked->uuid,
                after: ['status' => $target->value],
                before: $before,
                meta: ['branch' => $locked->branch_id],
                reason: $reason,
                severity: AuditSeverity::Notice,
            );

            return $locked;
        });

        return $moderated;
    }
}
