<?php

declare(strict_types=1);

use App\Modules\Reviews\Application\Actions\ManageReviewInvitation;
use App\Modules\Reviews\Application\Actions\ModerateReview;
use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Domain\Data\RatingInput;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The review itself
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §§9–15.
|
| The overall score is required; the detail is optional and is anchored to the
| stages that actually ran. A rating names a STAGE, and everything about it is
| read from that stage — so the shapes that would let somebody rate a service
| that was skipped, or an employee who was merely booked, are not reachable.
|
*/

/**
 * The uuid of the visit's Nth completed stage.
 */
function stageUuid(int $journeyId, int $index = 0): string
{
    /** @var list<JourneyStage> $stages */
    $stages = JourneyStage::query()
        ->where('service_journey_id', $journeyId)
        ->where('status', StageStatus::Completed->value)
        ->orderBy('position')
        ->get()
        ->all();

    return $stages[$index]->uuid;
}

it('records an overall rating, the detail beneath it, and spends the link', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $journey = $this->customerVisit($seed, $owner, $customer);
        $token = $this->reviewToken($journey, $owner);
        $stage = stageUuid((int) $journey->getKey());

        $review = app(SubmitReview::class)->byToken($token, new ReviewSubmission(
            overallRating: 5,
            publicComment: '  Lovely visit, thank you.  ',
            ratings: [
                new RatingInput($stage, RatingDimension::Service, 5),
                new RatingInput($stage, RatingDimension::Employee, 4),
            ],
        ));

        $ratings = ReviewRating::query()->where('review_id', $review->getKey())->get();

        expect($review->overall_rating)->toBe(5)
            // Trimmed, and otherwise exactly as written.
            ->and($review->public_comment)->toBe('Lovely visit, thank you.')
            ->and($review->status)->toBe(ReviewStatus::Submitted)
            ->and($review->branch_id)->toBe($seed['branch']->getKey())
            ->and($review->customer_id)->toBe($customer->getKey())
            ->and($ratings)->toHaveCount(2)
            // The targets were read from the stage, not from the request.
            ->and($ratings->firstWhere('dimension', RatingDimension::Service)?->service_id)->toBe($seed['service']->getKey())
            ->and($ratings->firstWhere('dimension', RatingDimension::Employee)?->employee_id)->not->toBeNull()
            ->and($ratings->every(fn (ReviewRating $r): bool => $r->journey_stage_id !== null))->toBeTrue()
            // The link is spent.
            ->and($this->invitationFor($journey)?->status)->toBe(InvitationStatus::Used);

        // And it cannot be used again.
        expect(fn () => app(SubmitReview::class)->byToken($token, new ReviewSubmission(1)))
            ->toThrow(ReviewFailed::class, 'That visit has already been reviewed.');

        expect(Review::query()->count())->toBe(1);
    });
});

it('accepts an overall rating on its own', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $token = $this->reviewToken($journey, $owner);

        $review = app(SubmitReview::class)->byToken($token, new ReviewSubmission(3));

        expect($review->overall_rating)->toBe(3)
            ->and($review->public_comment)->toBeNull()
            ->and(ReviewRating::query()->count())->toBe(0);
    });
});

it('refuses a score outside one to five', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $token = $this->reviewToken($journey, $owner);

        foreach ([0, 6, -1, 99] as $bad) {
            expect(fn () => app(SubmitReview::class)->byToken($token, new ReviewSubmission($bad)))
                ->toThrow(ReviewFailed::class);
        }

        // Nothing was written, and the link still works.
        expect(Review::query()->count())->toBe(0)
            ->and($this->invitationFor($journey)?->status)->toBe(InvitationStatus::Issued);
    });
});

it('refuses to rate a service that was not performed, or one from another visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // One service performed, one declined.
        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer(), ['completed', 'skipped']);
        $token = $this->reviewToken($journey, $owner);

        /** @var JourneyStage $skipped */
        $skipped = JourneyStage::query()
            ->where('service_journey_id', $journey->getKey())
            ->where('status', StageStatus::Skipped->value)
            ->sole();

        // Somebody else's visit entirely.
        $other = $this->customerVisit($seed, $owner, $this->seedCustomer('Layla Noor', '0750 123 4599'));

        $submit = app(SubmitReview::class);

        expect(fn () => $submit->byToken($token, new ReviewSubmission(5, null, [
            new RatingInput($skipped->uuid, RatingDimension::Service, 5),
        ])))->toThrow(ReviewFailed::class, 'That service was not part of this visit.');

        expect(fn () => $submit->byToken($token, new ReviewSubmission(5, null, [
            new RatingInput(stageUuid((int) $other->getKey()), RatingDimension::Service, 5),
        ])))->toThrow(ReviewFailed::class, 'That service was not part of this visit.');

        expect(Review::query()->count())->toBe(0);
    });
});

it('rates the employee who performed the work, never the one who was booked', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $stageId = stageUuid((int) $journey->getKey());

        /** @var JourneyStage $stage */
        $stage = JourneyStage::query()->where('uuid', $stageId)->sole();
        $performer = (int) $stage->employee_id;

        $token = $this->reviewToken($journey, $owner);

        app(SubmitReview::class)->byToken($token, new ReviewSubmission(4, null, [
            new RatingInput($stageId, RatingDimension::Employee, 2),
        ]));

        /** @var ReviewRating $rating */
        $rating = ReviewRating::query()->sole();

        // Read from the stage — the record of who ACTUALLY did it (§11).
        expect($rating->employee_id)->toBe($performer)
            ->and($rating->service_id)->toBeNull()
            ->and($rating->rating)->toBe(2);

        /*
         * And a visit where nobody was ever recorded against the stage has no
         * employee to rate. The refusal is the point: inventing a target would
         * credit or blame somebody the record does not name (§11).
         */
        $anonymous = $this->customerVisit($seed, $owner, $this->seedCustomer('Layla Noor', '0750 123 4599'), performer: false);
        $anonymousToken = $this->reviewToken($anonymous, $owner);

        expect(fn () => app(SubmitReview::class)->byToken($anonymousToken, new ReviewSubmission(4, null, [
            new RatingInput(stageUuid((int) $anonymous->getKey()), RatingDimension::Employee, 5),
        ])))->toThrow(ReviewFailed::class, 'Nobody is recorded as having performed that service.');

        // The service itself is still ratable.
        app(SubmitReview::class)->byToken($anonymousToken, new ReviewSubmission(4, null, [
            new RatingInput(stageUuid((int) $anonymous->getKey()), RatingDimension::Service, 5),
        ]));

        expect(ReviewRating::query()->count())->toBe(2);
    });
});

it('answers an unknown, revoked or expired link the same way', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $submit = app(SubmitReview::class);

        // Never existed.
        expect(fn () => $submit->byToken(str_repeat('a', 64), new ReviewSubmission(5)))
            ->toThrow(ReviewFailed::class, 'That review link is not valid.');

        // Not even the right shape — answered without touching the database.
        expect(fn () => $submit->byToken('not-a-token', new ReviewSubmission(5)))
            ->toThrow(ReviewFailed::class, 'That review link is not valid.');

        // Revoked.
        $revoked = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $revokedToken = $this->reviewToken($revoked, $owner);
        app(ManageReviewInvitation::class)->revoke($this->invitationFor($revoked), $owner);

        expect(fn () => $submit->byToken($revokedToken, new ReviewSubmission(5)))
            ->toThrow(ReviewFailed::class, 'That review link is not valid.');

        // Expired.
        $stale = $this->customerVisit($seed, $owner, $this->seedCustomer('Rana Ali', '0750 123 4598'));
        $staleToken = $this->reviewToken($stale, $owner);

        $this->travel(config('reviews.invitation_valid_days') + 1)->days();

        expect(fn () => $submit->byToken($staleToken, new ReviewSubmission(5)))
            ->toThrow(ReviewFailed::class, 'That review link is not valid.');

        expect(Review::query()->count())->toBe(0);
    });
});

it('keeps the customer\'s words when a review is hidden, and refuses to rewrite them', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $token = $this->reviewToken($journey, $owner);

        $review = app(SubmitReview::class)->byToken($token, new ReviewSubmission(1, 'It was terrible.'));

        app(ModerateReview::class)->hide($review, $owner, 'Abusive language');

        $hidden = $review->fresh();

        expect($hidden?->status)->toBe(ReviewStatus::Hidden)
            // Untouched (§15).
            ->and($hidden?->overall_rating)->toBe(1)
            ->and($hidden?->public_comment)->toBe('It was terrible.')
            ->and($hidden?->moderation_reason)->toBe('Abusive language')
            ->and($hidden?->moderated_by_id)->toBe($owner->uuid)
            ->and($hidden?->moderated_at)->not->toBeNull();

        // The model itself refuses an edit of what the customer submitted.
        expect(fn () => $hidden?->forceFill(['public_comment' => 'Actually it was fine.'])->save())
            ->toThrow(LogicException::class);

        expect(DB::connection('tenant')->table('audit_logs')->where('action', 'review.hidden')->count())->toBe(1);
    });
});

it('stores a comment exactly as written, so the surface that shows it must escape it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $token = $this->reviewToken($journey, $owner);

        $hostile = '<script>alert(1)</script>';

        $review = app(SubmitReview::class)->byToken($token, new ReviewSubmission(3, $hostile));

        // Nothing silently rewrote what the customer typed (§56) — which is
        // exactly why every surface escapes it rather than trusting the store.
        expect($review->public_comment)->toBe($hostile)
            ->and(e((string) $review->public_comment))->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');

        // And it is bounded.
        $long = $this->customerVisit($seed, $owner, $this->seedCustomer('Rana Ali', '0750 123 4598'));

        expect(fn () => app(SubmitReview::class)->byToken(
            $this->reviewToken($long, $owner),
            new ReviewSubmission(3, str_repeat('x', Review::MAX_COMMENT_LENGTH + 1)),
        ))->toThrow(ReviewFailed::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
