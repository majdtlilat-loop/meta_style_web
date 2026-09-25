<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Livewire\Center\NotificationInbox;
use App\Livewire\Center\Reviews;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use App\Modules\Reviews\Application\Actions\ManageReviewInvitation;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The surfaces
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §§21–23, 46.
|
| The public page is reached by a link and nothing else, shows the customer
| what they received and no internal identifier, and takes exactly one
| submission. The staff API is branch-scoped and permission-gated.
|
*/

it('shows the customer what they received, and nothing internal', function (): void {
    $center = $this->registerCenter();
    // Phase 15: the public review page lives on the center's own host.
    $reviewPage = 'http://'.$center['registration']->requested_slug.'.localhost:8000/r/';

    /** @var array{token: string, journey: string, stage: string} $context */
    $context = $this->asCenter($center['tenant'], function (): array {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());

        /** @var JourneyStage $stage */
        $stage = JourneyStage::query()->where('service_journey_id', $journey->getKey())->sole();

        return [
            'token' => $this->reviewToken($journey, $owner),
            'journey' => $journey->uuid,
            'stage' => $stage->uuid,
        ];
    });

    $page = $this->get($reviewPage.$context['token'])->assertOk()->getContent();

    expect($page)->toBeString();

    $page = (string) $page;

    expect($page)->toContain('Haircut')
        // The stage uuid is the only identifier on the page: a rating has to
        // name what it is rating, and it opens nothing (§21).
        ->toContain($context['stage'])
        ->toContain('noindex')
        ->toContain('no-referrer');

    /*
     * The visit's own uuid is NOT on the page. The token is — it is the form's
     * action, exactly as the invoice page posts back to its share link — and
     * that is the one identifier the reader already holds.
     */
    expect(str_contains($page, $context['journey']))->toBeFalse('the visit uuid reached the page')
        ->and(str_contains($page, $context['token']))->toBeTrue('the form must post back to the capability URL');

    // Nothing financial, and nothing about the customer.
    foreach (['invoice', 'Invoice', 'IQD', 'price', 'customer_id'] as $leak) {
        expect(str_contains($page, $leak))->toBeFalse("the page leaked {$leak}");
    }
});

it('takes one submission through the page and then shows the thank-you', function (): void {
    $center = $this->registerCenter();
    // Phase 15: the public review page lives on the center's own host.
    $reviewPage = 'http://'.$center['registration']->requested_slug.'.localhost:8000/r/';

    /** @var array{token: string, stage: string} $context */
    $context = $this->asCenter($center['tenant'], function (): array {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());

        /** @var JourneyStage $stage */
        $stage = JourneyStage::query()
            ->where('service_journey_id', $journey->getKey())
            ->where('status', StageStatus::Completed->value)
            ->sole();

        return ['token' => $this->reviewToken($journey, $owner), 'stage' => $stage->uuid];
    });

    $this->post($reviewPage.$context['token'], [
        'overall' => 4,
        'comment' => '<script>alert(1)</script> Lovely.',
        'service' => [$context['stage'] => 5],
        'employee' => [$context['stage'] => 3],
    ])->assertOk()->assertSee('Thank you');

    $this->asCenter($center['tenant'], function (): void {
        /** @var Review $review */
        $review = Review::query()->sole();

        expect($review->overall_rating)->toBe(4)
            ->and(ReviewRating::query()->count())->toBe(2)
            ->and($review->status)->toBe(ReviewStatus::Submitted);
    });

    // The link is spent: pressing the button again says thank you, and the
    // page behind it is gone (§22).
    $this->get($reviewPage.$context['token'])->assertOk()->assertSee('Thank you');
    $this->post($reviewPage.$context['token'], ['overall' => 1])->assertOk()->assertSee('Thank you');

    $this->asCenter($center['tenant'], function (): void {
        expect(Review::query()->count())->toBe(1);
    });
});

it('escapes what the customer wrote wherever staff read it back', function (): void {
    $center = $this->registerCenter();
    // Phase 15: the public review page lives on the center's own host.
    $reviewPage = 'http://'.$center['registration']->requested_slug.'.localhost:8000/r/';

    $token = $this->asCenter($center['tenant'], function (): string {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        return $this->reviewToken($this->customerVisit($seed, $owner, $this->seedCustomer()), $owner);
    });

    $this->post($reviewPage.$token, ['overall' => 2, 'comment' => '<script>alert(1)</script>'])->assertOk();

    $staffToken = $this->apiTokenFor($center['tenant']);

    $body = (string) $this->getJson('/api/v1/tenant/reviews', $this->tokenHeaders($staffToken))
        ->assertOk()
        ->getContent();

    // JSON carries the customer's exact words; whatever renders them escapes
    // them, and nothing stored markup on their behalf (§56).
    expect($body)->toContain('alert(1)');

    $this->asCenter($center['tenant'], function (): void {
        expect(e((string) Review::query()->sole()->public_comment))
            ->toBe('&lt;script&gt;alert(1)&lt;/script&gt;');
    });
});

it('answers an unknown, revoked or expired link with a plain 404', function (): void {
    $center = $this->registerCenter();
    // Phase 15: the public review page lives on the center's own host.
    $reviewPage = 'http://'.$center['registration']->requested_slug.'.localhost:8000/r/';

    $this->get($reviewPage.str_repeat('a', 64))->assertNotFound();
    $this->get($reviewPage.'not-a-token')->assertNotFound();

    $revoked = $this->asCenter($center['tenant'], function (): string {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $token = $this->reviewToken($journey, $owner);

        app(ManageReviewInvitation::class)->revoke($this->invitationFor($journey), $owner);

        return $token;
    });

    // The same answer as one that never existed: anything more precise would
    // confirm that a visit happened (§46).
    $this->get($reviewPage.$revoked)->assertNotFound();
    $this->post($reviewPage.$revoked, ['overall' => 5])->assertNotFound();
});

it('gives staff the list, the summary and the two moderation actions', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 5, service: 5, employee: 4, phone: '0750 123 4521');
        $this->leaveReview($seed, $owner, 2, phone: '0750 123 4522');
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $list = $this->getJson('/api/v1/tenant/reviews', $headers)->assertOk()->json('data.reviews');

    expect($list)->toHaveCount(2)
        ->and($list[0])->toHaveKeys(['id', 'status', 'overall_rating', 'comment', 'submitted_at', 'branch', 'ratings'])
        // Never an internal key (§23).
        ->and($list[0])->not->toHaveKeys(['service_journey_id', 'review_invitation_id', 'customer_id', 'branch_id']);

    $summary = $this->getJson('/api/v1/tenant/reviews/summary', $headers)->assertOk()->json('data.summary');

    expect($summary['overall']['count'])->toBe(2)
        ->and((float) $summary['overall']['average'])->toBe(3.5);

    $low = collect($list)->firstWhere('overall_rating', 2);

    $this->postJson('/api/v1/tenant/reviews/'.$low['id'].'/hide', ['reason' => 'Abusive language'], $headers)
        ->assertOk()
        ->assertJsonPath('data.review.status', ReviewStatus::Hidden->value);

    // Hidden leaves the average; the words are untouched.
    $after = $this->getJson('/api/v1/tenant/reviews/summary', $headers)->assertOk()->json('data.summary');

    expect($after['overall']['count'])->toBe(1)
        // JSON drops a zero fraction, so 5.0 arrives as 5.
        ->and((float) $after['overall']['average'])->toBe(5.0);

    $this->postJson('/api/v1/tenant/reviews/'.$low['id'].'/unhide', [], $headers)
        ->assertOk()
        ->assertJsonPath('data.review.status', ReviewStatus::Submitted->value);

    // Hiding without a stated reason is refused (§47).
    $this->postJson('/api/v1/tenant/reviews/'.$low['id'].'/hide', [], $headers)->assertStatus(422);
});

it('returns a fresh review link once, and never shows it again', function (): void {
    $center = $this->registerCenter();

    $journeyUuid = $this->asCenter($center['tenant'], function (): string {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        return $this->customerVisit($seed, $owner, $this->seedCustomer())->uuid;
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    // Called on the center's own host, as the Manager and a center app do: the
    // link is published on that host (Phase 15), the only address it answers on.
    $slug = $center['registration']->requested_slug;
    $invite = 'http://'.$slug.'.localhost:8000/api/v1/tenant/reviews/invitations/'.$journeyUuid;

    $url = (string) $this->postJson($invite, [], $headers)
        ->assertOk()
        ->json('data.url');

    expect($url)->toStartWith('http://'.$slug.'.localhost:8000/r/');

    // The link works, and no read anywhere can produce it again — asking for
    // one mints a NEW link and retires this one (§5).
    $this->get($url)->assertOk();

    $second = (string) $this->postJson($invite, [], $headers)
        ->assertOk()
        ->json('data.url');

    expect($second)->not->toBe($url);

    $this->get($url)->assertNotFound();
    $this->get($second)->assertOk();

    $this->asCenter($center['tenant'], function (): void {
        expect($this->invitationFor(ServiceJourney::query()->sole())?->status)
            ->toBe(InvitationStatus::Issued);
    });
});

it('gives the manager a screen that lists, filters and moderates', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 5, service: 5, phone: '0750 123 4531');
        $low = $this->leaveReview($seed, $owner, 1, phone: '0750 123 4532');

        $this->actingAs($owner, 'web');

        Livewire::test(Reviews::class)
            ->assertSee('5 / 5')
            ->assertSee('1 / 5')
            // Filtering narrows the list, and never widens the branch scope.
            ->set('rating', '1')
            ->assertDontSee('5 / 5')
            ->set('rating', '')
            // Hiding takes a reason.
            ->set('acting', $low->uuid)
            ->call('hide')
            ->assertSet('error', 'Say why this review is being hidden.')
            ->set('reason', 'Abusive language')
            ->call('hide')
            ->assertSet('error', '')
            ->assertSet('acting', '');

        expect($low->fresh()?->status)->toBe(ReviewStatus::Hidden)
            // The words are untouched (docs/22-REVIEWS.md §15).
            ->and($low->fresh()?->overall_rating)->toBe(1);

        Livewire::test(Reviews::class)->call('unhide', $low->uuid)->assertSet('error', '');

        expect($low->fresh()?->status)->toBe(ReviewStatus::Submitted);
    });
});

it('gives every staff member their own inbox screen, and no way to another', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 1, phone: '0750 123 4533');

        $this->actingAs($owner, 'web');

        Livewire::test(NotificationInbox::class)
            ->assertSee('needs a look')
            ->call('markAllRead')
            ->assertSet('error', '');

        // A colleague with their own inbox sees nothing of it, and cannot mark
        // a notification that is not theirs (docs/23-NOTIFICATIONS.md §16).
        $colleague = $this->seedStaffMember(SystemRole::Cashier, name: 'Cashier Noor');
        $this->actingAs($colleague, 'web');

        Livewire::test(NotificationInbox::class)
            ->assertSee(__('notifications_inbox.empty'))
            ->call('markRead', (string) NotificationRecipient::query()->value('uuid'))
            ->assertSet('error', 'That notification does not exist.');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
