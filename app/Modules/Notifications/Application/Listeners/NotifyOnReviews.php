<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Listeners;

use App\Kernel\Authorization\Permission;
use App\Kernel\Database\AfterCommit;
use App\Modules\Notifications\Application\CustomerInboxes;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Application\StaffTargets;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Reviews\Domain\Events\ReviewInvitationIssued;
use App\Modules\Reviews\Domain\Events\ReviewSubmitted;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;

/**
 * Two directions, and they are not symmetrical.
 *
 * ## Outward: "how did we do?"
 *
 * A signed-in customer gets their review invitation in their own inbox. The
 * notification carries the invitation's UUID and never its secret: they are
 * already authenticated, so their own account resolves the invitation and no
 * capability has to be copied into a second table to reach them
 * (docs/22-REVIEWS.md §§5, 40).
 *
 * A guest has no inbox and gets nothing here. Their link exists and the desk
 * can hand it over; Phase 12 builds no messaging provider to send it for them.
 *
 * ## Inward: "somebody is unhappy"
 *
 * A rating at or below `reviews.low_rating_threshold` raises an `important`
 * alert to the staff who can actually act on it — the people holding
 * `review.manage` in THAT branch. Never a role name, never every user in the
 * center (docs/23-NOTIFICATIONS.md §5).
 *
 * ## The review is the truth; the alert is not
 *
 * Both run after the submission commits. A customer's review is never lost, and
 * never refused, because an internal alert could not be written — the same
 * principle that keeps money independent of benefits (§11).
 */
final class NotifyOnReviews
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly CustomerInboxes $inboxes,
        private readonly StaffTargets $staff,
        private readonly AfterCommit $afterCommit,
    ) {}

    public function handleInvitationIssued(ReviewInvitationIssued $event): void
    {
        $this->afterCommit->run('notifications.review_invitation_ready', function () use ($event): void {
            $recipients = $this->inboxes->forCustomer($event->customerId);

            if ($recipients === []) {
                return;
            }

            $uuid = ReviewInvitation::query()->whereKey($event->invitationId)->value('uuid');

            if (! is_string($uuid)) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: NotificationType::ReviewInvitationReady,
                sourceType: 'review_invitation',
                sourceUuid: $uuid,
                recipients: $recipients,
                branchId: $event->branchId,
            ));
        });
    }

    public function handleReviewSubmitted(ReviewSubmitted $event): void
    {
        if ($event->overallRating > $this->threshold()) {
            return;
        }

        $this->afterCommit->run('notifications.low_rating_received', function () use ($event): void {
            $recipients = $this->staff->withPermission(Permission::ReviewManage, $event->branchId);

            if ($recipients === []) {
                return;
            }

            $uuid = Review::query()->whereKey($event->reviewId)->value('uuid');

            if (! is_string($uuid)) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: NotificationType::LowRatingReceived,
                sourceType: 'review',
                sourceUuid: $uuid,
                // The score, so the row is actionable at a glance. Never the
                // comment: a notification is not where customer-authored text
                // is stored (§7).
                params: ['rating' => $event->overallRating],
                recipients: $recipients,
                branchId: $event->branchId,
            ));
        });
    }

    /**
     * Read at the moment a review arrives, so changing it never rewrites which
     * past reviews did or did not raise an alert (docs/22-REVIEWS.md §17).
     */
    private function threshold(): int
    {
        $threshold = (int) config('reviews.low_rating_threshold', 2);

        return max(Review::MIN_RATING, min($threshold, Review::MAX_RATING));
    }
}
