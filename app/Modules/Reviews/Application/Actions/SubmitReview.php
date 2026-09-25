<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application\Actions;

use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Reviews\Application\ReviewEligibility;
use App\Modules\Reviews\Application\ReviewsAudit;
use App\Modules\Reviews\Domain\Data\RatingInput;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Events\ReviewSubmitted;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use App\Modules\Reviews\Domain\ReviewToken;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The customer's review of their visit.
 *
 *     BEGIN
 *       lock the invitation                    ← the capability, FOR UPDATE
 *       it is issued, not revoked, not expired, not already used
 *       lock the visit; it is completed and something was performed
 *       the overall score is 1–5
 *       every detail rating names a stage of THIS visit that was COMPLETED
 *       write the review and its ratings
 *       mark the invitation used
 *       announce it
 *     COMMIT
 *
 * ## One review per visit, decided by the database
 *
 * Two tabs, two phones, one link pressed twice: both requests take the
 * invitation row lock, the second reads `used` and is refused. And if that
 * check were removed tomorrow, `reviews.service_journey_id` and
 * `reviews.review_invitation_id` are UNIQUE — the invariant belongs to the
 * schema, not to a query somebody has to remember (docs/22-REVIEWS.md §14).
 *
 * ## The request never says what it is rating
 *
 * A rating names a STAGE, and everything about it — which service, which
 * employee — is read from that stage. A request therefore cannot rate a service
 * that was skipped, an employee who was booked but did not perform the work, or
 * anything from somebody else's visit. Those are not refusals somebody had to
 * remember to write; they are simply not reachable (§§11–12).
 *
 * ## Two ways in, one Action
 *
 * A GUEST holds a capability token. A signed-in CUSTOMER is already known, so
 * their invitation is resolved by uuid against their own customer record and no
 * secret is involved — which is why a review invitation in an inbox never has
 * to carry one (§§3, 40).
 */
final class SubmitReview
{
    public function __construct(
        private readonly ReviewEligibility $eligibility,
        private readonly ReviewsAudit $audit,
        private readonly Dispatcher $events,
    ) {}

    /**
     * A guest, or anybody holding the link.
     *
     * @throws ReviewFailed
     */
    public function byToken(#[SensitiveParameter] string $token, ReviewSubmission $submission, ?CarbonImmutable $now = null): Review
    {
        if (! ReviewToken::isWellFormed($token)) {
            // Answered without touching the database.
            throw ReviewFailed::invalidToken();
        }

        $hash = ReviewToken::hash($token);

        return $this->submit(
            static fn (): ?ReviewInvitation => ReviewInvitation::query()->where('token_hash', $hash)->lockForUpdate()->first(),
            $submission,
            'link',
            $now,
        );
    }

    /**
     * A signed-in customer, from their own account.
     *
     * @throws ReviewFailed
     */
    public function byCustomer(CustomerAccount $account, string $invitationUuid, ReviewSubmission $submission, ?CarbonImmutable $now = null): Review
    {
        $customerId = (int) $account->customer_id;

        return $this->submit(
            static fn (): ?ReviewInvitation => ReviewInvitation::query()
                ->where('uuid', $invitationUuid)
                // Somebody else's invitation is simply not found (§46).
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first(),
            $submission,
            'account',
            $now,
        );
    }

    /**
     * @param  callable(): ?ReviewInvitation  $resolve  run under the row lock
     *
     * @throws ReviewFailed
     */
    private function submit(callable $resolve, ReviewSubmission $submission, string $channel, ?CarbonImmutable $now): Review
    {
        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var Review $review */
        $review = DB::connection('tenant')->transaction(function () use ($resolve, $submission, $channel, $at): Review {
            $invitation = $resolve();

            if (! $invitation instanceof ReviewInvitation) {
                throw ReviewFailed::invalidToken();
            }

            /*
             * A used link gets the truthful answer, because whoever holds it
             * has already proved they had it and there is nothing left to
             * enumerate. Revoked and expired collapse into the same generic
             * refusal as a link that never existed (§§17, 46).
             */
            if ($invitation->status === InvitationStatus::Used) {
                throw ReviewFailed::alreadySubmitted();
            }

            if (! $invitation->isUsable($at)) {
                throw ReviewFailed::invalidToken();
            }

            /** @var ServiceJourney|null $journey */
            $journey = ServiceJourney::query()->whereKey($invitation->service_journey_id)->lockForUpdate()->first();

            if (! $journey instanceof ServiceJourney || ! $this->eligibility->isReviewable($journey)) {
                throw ReviewFailed::notEligible('That visit cannot be reviewed.');
            }

            $stages = $this->eligibility->ratableStages($journey);
            $rows = $this->resolveRatings($submission->ratings, $stages);

            /** @var Review $review */
            $review = Review::query()->create([
                'review_invitation_id' => $invitation->getKey(),
                'service_journey_id' => $journey->getKey(),
                'branch_id' => $invitation->branch_id,
                'customer_id' => $invitation->customer_id,
                'invoice_id' => $invitation->invoice_id,
                'status' => ReviewStatus::Submitted,
                'overall_rating' => $this->rating($submission->overallRating, 'An overall rating of 1 to 5 is required.'),
                'public_comment' => $this->comment($submission->publicComment),
                'submitted_at' => $at,
            ]);

            foreach ($rows as $row) {
                ReviewRating::query()->create(['review_id' => $review->getKey(), 'created_at' => $at] + $row);
            }

            $invitation->forceFill(['status' => InvitationStatus::Used, 'used_at' => $at])->save();

            $this->audit->recordSystem('review.submitted', Review::class, $review->uuid,
                after: ['overall_rating' => $review->overall_rating, 'ratings' => count($rows)],
                // Never the comment, never the customer, never the token.
                meta: ['journey' => $journey->uuid, 'branch' => $review->branch_id, 'channel' => $channel],
            );

            $this->events->dispatch(new ReviewSubmitted(
                (int) $review->getKey(),
                (int) $review->branch_id,
                $review->overall_rating,
                $review->customer_id === null ? null : (int) $review->customer_id,
            ));

            return $review;
        });

        return $review;
    }

    /**
     * Turns what was submitted into rows, reading every target from the stage.
     *
     * @param  list<RatingInput>  $ratings
     * @param  array<string, JourneyStage>  $stages
     * @return list<array<string, mixed>>
     *
     * @throws ReviewFailed
     */
    private function resolveRatings(array $ratings, array $stages): array
    {
        $rows = [];
        $seen = [];

        foreach ($ratings as $input) {
            $stage = $stages[$input->stageUuid] ?? null;

            if (! $stage instanceof JourneyStage) {
                // Not performed, or not part of this visit at all. One answer
                // for both: a stranger learns nothing from either.
                throw ReviewFailed::invalidRating('That service was not part of this visit.');
            }

            $key = $input->dimension->value.':'.$input->stageUuid;

            if (isset($seen[$key])) {
                throw ReviewFailed::invalidRating('That service was rated twice.');
            }

            $seen[$key] = true;

            $serviceId = $stage->serviceId();
            $employeeId = $stage->employee_id === null ? null : (int) $stage->employee_id;

            if ($input->dimension === RatingDimension::Service && $serviceId === null) {
                throw ReviewFailed::invalidRating('That service cannot be rated.');
            }

            /*
             * No employee was ever recorded against the stage. There is no
             * target, and inventing one would credit or blame somebody the
             * record does not name (§11).
             */
            if ($input->dimension === RatingDimension::Employee && $employeeId === null) {
                throw ReviewFailed::invalidRating('Nobody is recorded as having performed that service.');
            }

            $rows[] = [
                'dimension' => $input->dimension,
                'journey_stage_id' => $stage->getKey(),
                'service_id' => $input->dimension === RatingDimension::Service ? $serviceId : null,
                'employee_id' => $input->dimension === RatingDimension::Employee ? $employeeId : null,
                'rating' => $this->rating($input->rating, 'Each rating must be 1 to 5.'),
            ];
        }

        return $rows;
    }

    /**
     * @throws ReviewFailed
     */
    private function rating(int $value, string $refusal): int
    {
        if ($value < Review::MIN_RATING || $value > Review::MAX_RATING) {
            throw ReviewFailed::invalidRating($refusal, ['min' => Review::MIN_RATING, 'max' => Review::MAX_RATING]);
        }

        return $value;
    }

    /**
     * Customer-authored text: trimmed, bounded, stored exactly as written and
     * escaped by whatever renders it. Nothing here strips or rewrites it — a
     * sanitiser that quietly edited a customer's words would be publishing
     * something they did not say (§56).
     *
     * @throws ReviewFailed
     */
    private function comment(?string $comment): ?string
    {
        $comment = $comment === null ? null : trim($comment);

        if ($comment === null || $comment === '') {
            return null;
        }

        if (mb_strlen($comment) > Review::MAX_COMMENT_LENGTH) {
            throw ReviewFailed::policy('That comment is longer than '.Review::MAX_COMMENT_LENGTH.' characters.');
        }

        return $comment;
    }
}
