<?php

declare(strict_types=1);

use App\Kernel\Authorization\BranchScope;
use App\Modules\Reviews\Application\Actions\ModerateReview;
use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Application\RatingSummary;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Enums\InvitationStatus;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The averages, and the one rule about what counts
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §§13, 24–25.
|
| `submitted` and `flagged` count. `hidden` does not. One canonical read model,
| so no two screens can disagree about a center's own score.
|
*/

/**
 * How many queries `$work` costs.
 */
function reviewQueries(callable $work): int
{
    $count = 0;

    DB::connection('tenant')->listen(function () use (&$count): void {
        $count++;
    });

    $work();

    return $count;
}

it('answers zero reviews without pretending to have an average', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $this->seedBookableCenter();
        $this->ownerWithCatalogAccess();

        expect(app(RatingSummary::class)->overall(BranchScope::all()))->toBe([
            'count' => 0,
            // Not 0.0, and not 5: a center with no reviews has no score.
            'average' => null,
            'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        ])
            ->and(app(RatingSummary::class)->byService(BranchScope::all()))->toBe([])
            ->and(app(RatingSummary::class)->byEmployee(BranchScope::all()))->toBe([]);
    });
});

it('averages the overall score, spreads it one to five, and breaks it down by service and person', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->leaveReview($seed, $owner, 5, service: 5, employee: 4, phone: '0750 123 4501');
        $this->leaveReview($seed, $owner, 4, service: 4, employee: 5, phone: '0750 123 4502');
        $this->leaveReview($seed, $owner, 2, service: 3, employee: 3, phone: '0750 123 4503');

        $summary = app(RatingSummary::class);
        $overall = $summary->overall(BranchScope::all());

        expect($overall['count'])->toBe(3)
            // (5 + 4 + 2) / 3, to one decimal, decided once (§25).
            ->and($overall['average'])->toBe(3.7)
            ->and($overall['distribution'])->toBe([1 => 0, 2 => 1, 3 => 0, 4 => 1, 5 => 1]);

        $services = $summary->byService(BranchScope::all());
        $employees = $summary->byEmployee(BranchScope::all());

        expect($services[$seed['service']->getKey()])->toBe(['count' => 3, 'average' => 4.0])
            ->and($employees[$seed['employee']->getKey()])->toBe(['count' => 3, 'average' => 4.0]);
    });
});

it('drops a hidden review from the averages and keeps a flagged one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $kept = $this->leaveReview($seed, $owner, 5, service: 5, phone: '0750 123 4504');
        $flagged = $this->leaveReview($seed, $owner, 4, service: 4, phone: '0750 123 4505');
        $hidden = $this->leaveReview($seed, $owner, 1, service: 1, phone: '0750 123 4506');

        $moderate = app(ModerateReview::class);
        $moderate->flag($flagged, $owner, 'Reads oddly');
        $moderate->hide($hidden, $owner, 'Abusive language');

        $summary = app(RatingSummary::class);

        /*
         * Flagging asks staff to LOOK at something; hiding is the decision to
         * take it out of the center's picture. Only the decision moves the
         * number — an average that shifted the moment somebody clicked "look at
         * this" would measure attention, not service (§24).
         */
        expect($summary->overall(BranchScope::all())['count'])->toBe(2)
            ->and($summary->overall(BranchScope::all())['average'])->toBe(4.5)
            ->and($summary->byService(BranchScope::all())[$seed['service']->getKey()])->toBe(['count' => 2, 'average' => 4.5]);

        // Unhidden, it counts again — nothing was destroyed.
        $moderate->unhide($hidden->fresh(), $owner);

        expect($summary->overall(BranchScope::all())['count'])->toBe(3)
            ->and($summary->overall(BranchScope::all())['average'])->toBe(3.3);

        unset($kept);
    });
});

it('never includes reviews outside the supplied branch scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $main = $this->seedBookableCenter();
        $secondBranch = $this->seedBranch();
        $this->openEveryDay($secondBranch);

        $second = $main;
        $second['branch'] = $secondBranch;
        $second['employee'] = $this->seedBookableEmployee($main['service'], $secondBranch, 'Second Branch Stylist');

        $owner = $this->ownerWithCatalogAccess();
        $this->leaveReview($main, $owner, 5, service: 5, employee: 5, phone: '0750 123 4591');
        $this->leaveReview($second, $owner, 1, service: 1, employee: 1, phone: '0750 123 4592');

        $summary = app(RatingSummary::class);
        $scope = BranchScope::limitedTo([(int) $main['branch']->getKey()]);

        expect($summary->overall($scope))->toBe([
            'count' => 1,
            'average' => 5.0,
            'distribution' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 1],
        ])
            ->and($summary->byService($scope))->toBe([
                $main['service']->getKey() => ['count' => 1, 'average' => 5.0],
            ])
            ->and($summary->byEmployee($scope))->toBe([
                $main['employee']->getKey() => ['count' => 1, 'average' => 5.0],
            ]);
    });
});

it('answers the whole center in a flat number of queries, whatever the volume', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $summary = app(RatingSummary::class);

        foreach ([4507, 4508] as $index => $phone) {
            $this->leaveReview($seed, $owner, 4 + $index % 2, service: 4, employee: 5, phone: '0750 123 '.$phone);
        }

        $baseline = reviewQueries(static function () use ($summary): void {
            $summary->overall(BranchScope::all());
            $summary->byService(BranchScope::all());
            $summary->byEmployee(BranchScope::all());
        });

        foreach ([4509, 4510, 4511] as $phone) {
            $this->leaveReview($seed, $owner, 3, service: 3, employee: 3, phone: '0750 123 '.$phone);
        }

        $after = reviewQueries(static function () use ($summary): void {
            $summary->overall(BranchScope::all());
            $summary->byService(BranchScope::all());
            $summary->byEmployee(BranchScope::all());
        });

        // Three grouped aggregates, whether there are two reviews or five. A
        // per-employee average computed in a loop would grow with every hire
        // (§52).
        expect($baseline)->toBe(3)
            ->and($after)->toBe($baseline);
    });
});

it('lets exactly one of two simultaneous submissions of the same link through', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $journey = $this->customerVisit($seed, $owner, $this->seedCustomer());
        $token = $this->reviewToken($journey, $owner);
        $invitation = $this->invitationFor($journey);

        [$other, $release] = secondTenantConnection('tenant_review_submit');

        try {
            // Another request is inside its own transaction on this invitation
            // — the row lock every submission takes first (§14).
            $other->beginTransaction();
            $other->table('review_invitations')->where('id', $invitation->getKey())->lockForUpdate()->get();

            expect(waitsForTenantLock(fn () => app(SubmitReview::class)->byToken($token, new ReviewSubmission(5))))->toBeTrue()
                ->and(Review::query()->count())->toBe(0);

            // That request commits its own review and spends the link.
            $other->table('reviews')->insert([
                'uuid' => (string) Str::uuid(),
                'review_invitation_id' => $invitation->getKey(),
                'service_journey_id' => $journey->getKey(),
                'branch_id' => $invitation->branch_id,
                'customer_id' => $invitation->customer_id,
                'status' => 'submitted',
                'overall_rating' => 5,
                'submitted_at' => now()->utc(),
                'created_at' => now()->utc(),
                'updated_at' => now()->utc(),
            ]);
            $other->table('review_invitations')->where('id', $invitation->getKey())
                ->update(['status' => InvitationStatus::Used->value, 'used_at' => now()->utc()]);
            $other->commit();
        } finally {
            $release();
        }

        // The loser reads the committed state and is refused. One visit, one
        // review — and the unique indexes are the backstop underneath it.
        expect(fn () => app(SubmitReview::class)->byToken($token, new ReviewSubmission(1)))
            ->toThrow(ReviewFailed::class, 'That visit has already been reviewed.');

        expect(Review::query()->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
