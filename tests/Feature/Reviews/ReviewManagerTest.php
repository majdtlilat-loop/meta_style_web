<?php

declare(strict_types=1);

use App\Kernel\Authorization\BranchScope;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Reviews;
use App\Livewire\Center\Reviews\Summary;
use App\Modules\Reviews\Application\RatingSummary;
use App\Modules\Reviews\Application\ReviewDays;
use App\Modules\Reviews\Application\ReviewsQuery;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager reviews page
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §§18, 23, 24, 50. Branch-local days with the last one
| included, keyset pages by uuid, per-service and per-employee averages that
| follow the chosen branch and days, filters by what was rated — and a
| downgrade that stops issuing links and nothing else.
|
*/

it('includes the last day of a date range, counted on the branch clock', function (): void {
    $center = $this->registerCenter();

    $today = $this->asCenter($center['tenant'], function (): string {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $review = $this->leaveReview($seed, $owner, 4, service: 4, phone: '0750 123 4701');
        $today = BranchClock::localDate(CarbonImmutable::now(), $seed['branch']->timezone);
        $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

        $query = app(ReviewsQuery::class);

        // `to` = today used to compare a DATETIME with midnight and drop every
        // review written today.
        expect(collect($query->page($owner, ['from' => $today, 'to' => $today])['reviews'])->pluck('uuid')->all())->toBe([$review->uuid])
            ->and($query->page($owner, ['to' => $yesterday])['reviews'])->toBe([])
            ->and(app(RatingSummary::class)->overall(BranchScope::all(), null, null, null, ReviewDays::between($today, $today))['count'])->toBe(1)
            ->and(app(RatingSummary::class)->overall(BranchScope::all(), null, null, null, ReviewDays::between(null, $yesterday))['count'])->toBe(0);

        return $today;
    });

    // The API reads the same way.
    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($center['tenant'])))
        ->getJson("/api/v1/tenant/reviews?to={$today}")
        ->assertOk()
        ->assertJsonCount(1, 'data.reviews');
});

it('pages older reviews by a uuid cursor', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $first = $this->leaveReview($seed, $owner, 3, phone: '0750 123 4711');
        $second = $this->leaveReview($seed, $owner, 4, phone: '0750 123 4712');
        $third = $this->leaveReview($seed, $owner, 5, phone: '0750 123 4713');

        $query = app(ReviewsQuery::class);
        $page = $query->pageBefore($owner, [], null, 2);

        expect(collect($page['reviews'])->pluck('uuid')->all())->toBe([$third->uuid, $second->uuid])
            ->and($page['has_more'])->toBeTrue();

        $next = $query->pageBefore($owner, [], $second->uuid, 2);

        expect(collect($next['reviews'])->pluck('uuid')->all())->toBe([$first->uuid])
            ->and($next['has_more'])->toBeFalse();

        // An unknown cursor starts from the newest rather than guessing.
        expect(collect($query->pageBefore($owner, [], '00000000-0000-4000-8000-000000000000', 2)['reviews'])->pluck('uuid')->first())
            ->toBe($third->uuid);
    });
});

it('filters by what was rated and shows averages per service and per employee', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 5, service: 5, employee: 4, phone: '0750 123 4721');
        $this->leaveReview($seed, $owner, 2, phone: '0750 123 4722');

        Livewire::actingAs($owner)->test(Reviews::class)
            ->assertSee('5 / 5')
            ->assertSee('2 / 5')
            ->set('service', $seed['service']->uuid)
            ->assertSee('5 / 5')
            ->assertDontSee('2 / 5')
            ->set('employee', $seed['employee']->uuid)
            ->assertSee('5 / 5')
            ->call('clearFilters')
            ->assertSee('2 / 5');

        Livewire::actingAs($owner)->test(Summary::class)
            ->assertViewHas('count', 2)
            ->assertViewHas('services', fn (array $rows): bool => $rows[0]['average'] === 5.0 && $rows[0]['count'] === 1)
            ->assertViewHas('employees', fn (array $rows): bool => $rows[0]['average'] === 4.0);

        // A branch with no reviews has none — the summary follows the branch.
        $quiet = $this->seedBranch('Quiet Branch');

        Livewire::actingAs($owner)->test(Summary::class, ['branch' => $quiet->uuid])->assertViewHas('count', 0);
    });
});

it('keeps reading and moderating after a downgrade, and locks the page with nothing collected', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();
        $this->revokeEntitlement('reviews');

        Livewire::actingAs($owner)->test(Reviews::class)->assertViewIs('livewire.center.feature-locked');

        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $review = $this->leaveReview($seed, $owner, 1, phone: '0750 123 4731');
        $this->revokeEntitlement('reviews');

        Livewire::actingAs($owner)->test(Reviews::class)
            ->assertViewIs('livewire.center.reviews')
            ->assertSee('feature-lock-notice', false)
            ->call('moderate', $review->uuid)
            ->set('reason', 'Abusive language')
            ->call('hide')
            ->assertSet('error', '');

        expect($review->fresh()?->status)->toBe(ReviewStatus::Hidden);
    });
});

it('renders the reviews page in every interface language with no raw keys', function (string $locale, string $direction): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug, $locale, $direction): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->leaveReview($seed, $owner, 4, service: 4, employee: 4, phone: '0750 123 4741');

        $this->actingAs($owner);

        $html = (string) $this->get("http://{$slug}.localhost:8000/manager/reviews?locale={$locale}")
            ->assertOk()
            ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
            ->assertSee('4 / 5')
            ->getContent();

        expect(preg_match('/\bmanager_(customers|benefits)\.[a-z_]+\.[a-z_]+/', strip_tags($html)))->toBe(0);
    });
})->with([
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
    'Kurdish Sorani' => ['ckb', 'rtl'],
]);

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
