<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Models\Notification;
use App\Modules\Notifications\Domain\Models\NotificationPreference;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Application\PublicReview;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Reviews and notifications — tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/22-REVIEWS.md §49.
|
| A review belongs to the center whose visit it is about, and an inbox to the
| person inside that center. A capability link minted at one center opens
| nothing at another — including when the two are looking at the very same
| secret — and with no center bound every one of these queries fails rather
| than answering from somewhere.
|
*/

it('never resolves one center\'s review link inside another', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    /** @var string $token */
    $token = $this->asCenter($alpha['tenant'], function (): string {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer('Sara Ahmed', '0750 123 4567'));

        return $this->reviewToken($journey, $owner);
    });

    $this->asCenter($beta['tenant'], function () use ($token): void {
        $this->grantReviews();
        $this->seedBookableCenter();
        $this->ownerWithCatalogAccess();

        // Alpha's secret, presented at Beta. It opens nothing, and the refusal
        // is the same one an invented token gets (§46).
        expect(app(PublicReview::class)->invitation($token))->toBeNull()
            ->and(fn () => app(SubmitReview::class)->byToken($token, new ReviewSubmission(5)))
            ->toThrow(ReviewFailed::class, 'That review link is not valid.')
            ->and(ReviewInvitation::query()->count())->toBe(0)
            ->and(Review::query()->count())->toBe(0);
    });

    // And it still works where it belongs.
    $this->asCenter($alpha['tenant'], function () use ($token): void {
        expect(app(SubmitReview::class)->byToken($token, new ReviewSubmission(5))->overall_rating)->toBe(5)
            ->and(Review::query()->count())->toBe(1);
    });
});

it('keeps two centers\' reviews and ratings apart, even when their ids collide', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 5, service: 5, employee: 5, phone: '0750 123 4567');

        expect(Review::query()->sole()->getKey())->toBe(1)
            ->and(Review::query()->sole()->overall_rating)->toBe(5);
    });

    $this->asCenter($beta['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 1, service: 1, employee: 1, phone: '0750 123 4567');

        // Row 1 in both databases, and nothing in common.
        expect(Review::query()->count())->toBe(1)
            ->and(Review::query()->sole()->getKey())->toBe(1)
            ->and(Review::query()->sole()->overall_rating)->toBe(1)
            ->and(ReviewRating::query()->count())->toBe(2);
    });

    $this->asCenter($alpha['tenant'], function (): void {
        expect(Review::query()->sole()->overall_rating)->toBe(5)
            ->and(ReviewRating::query()->count())->toBe(2);
    });
});

it('keeps one center\'s inbox out of another, for the same person number', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 1, phone: '0750 123 4567');

        $staff = new Recipient(RecipientKind::Staff, (int) $owner->getKey());

        expect(app(Inbox::class)->unreadCount($staff))->toBe(1);
    });

    $this->asCenter($beta['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();

        // The same staff row NUMBER, a different center: an empty inbox.
        $staff = new Recipient(RecipientKind::Staff, (int) $owner->getKey());

        expect(app(Inbox::class)->unreadCount($staff))->toBe(0)
            ->and(Notification::query()->count())->toBe(0)
            ->and(NotificationRecipient::query()->count())->toBe(0);
    });
});

it('fails closed when no center is bound, and leaves none bound after review work', function (): void {
    $center = $this->registerCenter();

    expect(fn (): int => ReviewInvitation::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => Review::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => ReviewRating::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => Notification::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => NotificationRecipient::query()->count())->toThrow(TenantConnectionNotInitialized::class)
        ->and(fn (): int => NotificationPreference::query()->count())->toThrow(TenantConnectionNotInitialized::class);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $this->leaveReview($seed, $this->ownerWithCatalogAccess(), 4);
    });

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('puts every Phase 12 table in the center database, with no tenant column', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $tables = [
            'review_invitations', 'reviews', 'review_ratings',
            'notifications', 'notification_recipients', 'notification_preferences',
        ];

        foreach ($tables as $table) {
            expect(Schema::connection('tenant')->hasTable($table))->toBeTrue("{$table} should exist")
                ->and(Schema::connection('tenant')->hasColumn($table, 'tenant_id'))->toBeFalse("{$table} must not carry tenant_id");
        }

        // The plaintext capability is nowhere in the schema (§5).
        expect(Schema::connection('tenant')->hasColumn('review_invitations', 'token_hash'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasColumn('review_invitations', 'token'))->toBeFalse();

        // And no review or rating state leaked onto the operational tables.
        foreach (['appointments', 'journey_stages', 'service_journeys', 'invoices', 'employees'] as $table) {
            foreach (['rating', 'review_id', 'average_rating', 'reviews_count'] as $column) {
                expect(Schema::connection('tenant')->hasColumn($table, $column))->toBeFalse("{$table}.{$column}");
            }
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
