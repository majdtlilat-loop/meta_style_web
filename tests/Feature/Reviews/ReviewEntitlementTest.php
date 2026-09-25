<?php

declare(strict_types=1);

use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Reviews\Application\Actions\ManageReviewInvitation;
use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Application\ReviewSync;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| `reviews` stands on its own
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §§3, 18 · ADR-066, ADR-051.
|
| A review is about a completed ServiceJourney with a performed stage — and a
| journey is a WALK-IN as readily as a booked visit. `reviews` therefore depends
| on no other entitlement: a dependency on `booking` would have the closure
| silently drop it from a walk-in-only center, and would tie a feature about
| visits that already happened to one about arranging future ones.
|
| Separately, every ServiceJourney ACTION gates on `booking` today (Phase 7/8).
| That governs PERFORMING a visit; these prove it does not govern reviewing one
| that is already finished.
|
*/

it('gives `reviews` no prerequisite at all, so a walk-in-only center keeps it', function (): void {
    $catalog = app(EntitlementCatalog::class);

    expect($catalog->has('reviews'))->toBeTrue()
        ->and($catalog->requires('reviews'))->toBe([]);

    /*
     * The closure is what would have bitten: it drops any granted key whose
     * dependencies are unmet, silently and with no error anywhere. `reviews`
     * granted on its own survives it; `queue_voice` — which really does depend
     * on something — does not, which is what proves the closure is running at
     * all rather than passing everything through.
     */
    expect($catalog->applyDependencies(['reviews']))->toBe(['reviews'])
        ->and($catalog->applyDependencies(['queue_voice']))->toBe([]);
});

it('reviews a walk-in visit that has no appointment at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());

        // The premise, PINNED: no appointment was invented to satisfy the
        // column, which is exactly what it is nullable for (ADR-051).
        expect($journey->appointment_id)->toBeNull()
            ->and($journey->source->value)->toBe('walk_in');

        $invitation = $this->invitationFor($journey);

        expect($invitation)->not->toBeNull()
            ->and($invitation?->status)->toBe(InvitationStatus::Issued);

        $review = app(SubmitReview::class)->byToken($this->reviewToken($journey, $owner), new ReviewSubmission(5));

        expect($review->overall_rating)->toBe(5)
            ->and($review->service_journey_id)->toBe($journey->getKey());
    });
});

it('issues nothing while `reviews` is off, and everything once it is on', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        // `booking` alone: visits can be performed, and nothing is reviewable.
        $this->grantEntitlement('booking', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $before = $this->customerVisit($seed, $owner, $this->seedCustomer());

        expect($this->invitationFor($before))->toBeNull()
            ->and(app(ReviewSync::class)->reconcile(CarbonImmutable::now()->utc()->subDays(30)))->toBe(0);

        // And the desk cannot mint one by hand either.
        expect(fn () => app(ManageReviewInvitation::class)->reissue($before, $owner))
            ->toThrow(EntitlementRequired::class);

        // Switched on: the next completed visit is reviewable.
        $this->grantReviewsOnly();

        $after = $this->customerVisit($seed, $owner, $this->seedCustomer('Layla Noor', '0750 123 4599'));

        expect($this->invitationFor($after))->not->toBeNull();
    });
});

it('keeps a finished walk-in reviewable after the center loses booking', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $reviewable = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $reissuable = $this->customerVisit($seed, $owner, $this->seedCustomer('Rana Ali', '0750 123 4598'));

        // The center stops selling booking. Its history does not go anywhere.
        $this->revokeEntitlement('booking');

        expect(app(Entitlements::class)->enabled('booking'))->toBeFalse()
            // The closure leaves `reviews` alone, because it depends on nothing.
            ->and(app(Entitlements::class)->enabled('reviews'))->toBeTrue()
            ->and($this->invitationFor($reviewable)?->status)->toBe(InvitationStatus::Issued);

        // The customer can still leave their review...
        $review = app(SubmitReview::class)->byToken($this->reviewToken($reviewable, $owner), new ReviewSubmission(4));

        expect($review->overall_rating)->toBe(4)
            ->and(Review::query()->count())->toBe(1);

        // ...and the desk can still issue a fresh link for a visit that is
        // already finished. Reviewing is not performing (§18).
        expect(fn () => app(ManageReviewInvitation::class)->reissue($reissuable, $owner))->not->toThrow(EntitlementRequired::class);

        expect(ReviewInvitation::query()->count())->toBe(2);
    });
});

it('leaves the booked review flow exactly as it was', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $journey = $this->bookedCustomerVisit($seed, $owner, $customer);

        // A booked visit: it HAS an appointment, and it is reviewed the same way.
        expect($journey->appointment_id)->not->toBeNull()
            ->and($journey->source->value)->toBe('appointment');

        $invitation = $this->invitationFor($journey);

        expect($invitation)->not->toBeNull()
            ->and($invitation?->customer_id)->toBe($customer->getKey())
            ->and($invitation?->branch_id)->toBe($seed['branch']->getKey());

        $review = app(SubmitReview::class)->byToken($this->reviewToken($journey, $owner), new ReviewSubmission(5));

        expect($review->overall_rating)->toBe(5)
            ->and($this->invitationFor($journey)?->status)->toBe(InvitationStatus::Used);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
