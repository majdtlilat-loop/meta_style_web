<?php

declare(strict_types=1);

use App\Kernel\Database\AfterCommitFailed;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Reviews\Application\Actions\ManageReviewInvitation;
use App\Modules\Reviews\Application\ReviewSync;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\Reviews\Domain\ReviewToken;
use App\Modules\ServiceJourney\Domain\Events\JourneyCompleted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/*
|--------------------------------------------------------------------------
| One completed visit, one invitation, one secret
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §§3–6.
|
| The invitation is the capability. What is stored is its digest; the plaintext
| exists once, in the URL the minting call returned, and a later read cannot
| produce it again.
|
*/

function sinceLastMonth(): CarbonImmutable
{
    return CarbonImmutable::now()->utc()->subDays(30);
}

it('gives a completed visit exactly one invitation, and stores only its digest', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $journey = $this->customerVisit($seed, $owner, $customer);

        $invitation = $this->invitationFor($journey);

        expect($invitation)->not->toBeNull()
            ->and($invitation?->status)->toBe(InvitationStatus::Issued)
            ->and($invitation?->branch_id)->toBe($seed['branch']->getKey())
            ->and($invitation?->customer_id)->toBe($customer->getKey())
            // 64 hex characters of SHA-256, and nothing that could be a secret.
            ->and($invitation?->token_hash)->toMatch('/^[a-f0-9]{64}$/');

        // Heard twice — a retry, a second worker, a reconciliation pass.
        DB::connection('tenant')->transaction(fn () => Event::dispatch(new JourneyCompleted((int) $journey->getKey(), $seed['branch']->getKey())));

        expect(ReviewInvitation::query()->count())->toBe(1)
            ->and(app(ReviewSync::class)->reconcile(sinceLastMonth()))->toBe(0);
    });
});

it('never invites for a visit where nothing was performed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        // The customer declined the service and left. The visit closed, but
        // nothing happened in it (docs/22-REVIEWS.md §3).
        $skipped = $this->customerVisit($seed, $owner, $customer, ['skipped']);

        // And a visit still running is not over.
        $open = $this->customerVisit($seed, $owner, $this->seedCustomer('Layla Noor', '0750 123 4599'), ['waiting'], complete: false);

        expect($this->invitationFor($skipped))->toBeNull()
            ->and($this->invitationFor($open))->toBeNull()
            ->and(ReviewInvitation::query()->count())->toBe(0)
            ->and(app(ReviewSync::class)->reconcile(sinceLastMonth()))->toBe(0);
    });
});

it('repairs an invitation the after-commit callback never wrote', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        // The process died between the commit and the callback: no listener
        // ran at all.
        Event::fake([JourneyCompleted::class]);
        $journey = $this->customerVisit($seed, $owner, $customer);

        expect(ReviewInvitation::query()->count())->toBe(0);

        expect(app(ReviewSync::class)->reconcile(sinceLastMonth()))->toBe(1)
            ->and($this->invitationFor($journey))->not->toBeNull()
            // And running it again writes nothing.
            ->and(app(ReviewSync::class)->reconcile(sinceLastMonth()))->toBe(0)
            ->and(ReviewInvitation::query()->count())->toBe(1);
    });
});

it('keeps the visit even when the invitation cannot be written', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        Exceptions::fake([AfterCommitFailed::class]);

        ReviewInvitation::creating(static function (): void {
            throw new RuntimeException('Review storage unavailable');
        });

        // The visit still closes. A salon floor cannot be blocked by feedback.
        $journey = $this->customerVisit($seed, $owner, $customer);

        expect($journey->fresh()?->status->value)->toBe('completed')
            ->and(ReviewInvitation::query()->count())->toBe(0);
    });
})->group('after-commit');

it('issues a fresh link on reissue and stops the old one working', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $journey = $this->customerVisit($seed, $owner, $customer);
        $first = $this->invitationFor($journey);

        // A stored secret cannot be read back, so "send it again" mints a new
        // one and the old digest is gone (§5).
        $secret = app(ManageReviewInvitation::class)->reissue($journey, $owner);

        $second = $this->invitationFor($journey);

        expect(ReviewToken::isWellFormed($secret))->toBeTrue()
            ->and(ReviewInvitation::query()->count())->toBe(1)
            ->and($second?->uuid)->not->toBe($first?->uuid)
            ->and($second?->token_hash)->toBe(ReviewToken::hash($secret))
            ->and($second?->token_hash)->not->toBe($first?->token_hash)
            // The plaintext is nowhere in the row.
            ->and(json_encode($second?->toArray()))->not->toContain($secret);

        // And the audit trail carries neither the secret nor its digest (§45).
        $audit = DB::connection('tenant')->table('audit_logs')
            ->whereIn('action', ['review.invitation_issued', 'review.invitation_reissued'])
            ->get()
            ->map(static fn (object $row): string => json_encode($row) ?: '')
            ->implode(' ');

        expect(str_contains($audit, $secret))->toBeFalse('the plaintext secret reached an audit row')
            ->and(str_contains($audit, (string) $second?->token_hash))->toBeFalse('the token digest reached an audit row');
    });
});

it('revokes a link without touching the visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        $journey = $this->customerVisit($seed, $owner, $customer);
        $invitation = $this->invitationFor($journey);

        app(ManageReviewInvitation::class)->revoke($invitation, $owner);

        expect($this->invitationFor($journey)?->status)->toBe(InvitationStatus::Revoked)
            ->and($journey->fresh()?->status->value)->toBe('completed')
            // Revoking twice is not an error.
            ->and(fn () => app(ManageReviewInvitation::class)->revoke($invitation->fresh(), $owner))->not->toThrow(ReviewFailed::class);
    });
});

it('issues nothing after the center loses reviews, and keeps the link it already gave', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer();

        // A visit reviewed under the entitlement.
        $before = $this->customerVisit($seed, $owner, $customer);
        $issued = $this->invitationFor($before);

        $this->revokeEntitlement('reviews');

        // A new visit afterwards gets nothing — not from the listener, and not
        // from reconciliation (docs/22-REVIEWS.md §18).
        $after = $this->customerVisit($seed, $owner, $this->seedCustomer('Rana Ali', '0750 123 4598'));

        expect($this->invitationFor($after))->toBeNull()
            ->and(app(ReviewSync::class)->reconcile(sinceLastMonth()))->toBe(0)
            // The capability the center already handed out is untouched.
            ->and($this->invitationFor($before)?->uuid)->toBe($issued?->uuid)
            ->and($this->invitationFor($before)?->status)->toBe(InvitationStatus::Issued);

        // And the desk cannot mint a new one while the entitlement is gone.
        expect(fn () => app(ManageReviewInvitation::class)->reissue($after, $owner))
            ->toThrow(EntitlementRequired::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
