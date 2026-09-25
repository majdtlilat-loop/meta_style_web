<?php

declare(strict_types=1);

use App\Modules\Reviews\Application\ReviewPresenter;
use App\Modules\Reviews\Application\ReviewsQuery;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Query budgets — the staff review list
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §52.
|
| A page of reviews must cost the same number of queries at two rows as at
| seven. Asserted as "does not grow", not as a magic number: a presenter that
| resolved a service name per rating fails here the day it is written, rather
| than on the afternoon a center has collected a year of feedback.
|
*/

function reviewListQueries(callable $work): int
{
    $count = 0;

    DB::connection('tenant')->listen(function () use (&$count): void {
        $count++;
    });

    $work();

    return $count;
}

it('costs the same to list two reviews as to list seven', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantReviews();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $query = app(ReviewsQuery::class);
        $presenter = app(ReviewPresenter::class);

        $list = function () use ($query, $presenter, $owner): void {
            $page = $query->page($owner, []);
            $presenter->collection($page['reviews'], $page['ratings']);
        };

        foreach ([4541, 4542] as $phone) {
            $this->leaveReview($seed, $owner, 4, service: 4, employee: 5, phone: '0750 123 '.$phone);
        }

        /*
         * Warmed first. `TenantLocales` is `scoped`, so the very first read in a
         * request also pays for the center's language settings — measuring a
         * cold cache against a warm one shows the count FALLING as rows are
         * added, which says nothing about whether the page is O(1) in reviews.
         */
        $list();

        $baseline = reviewListQueries($list);

        foreach ([4543, 4544, 4545, 4546, 4547] as $phone) {
            $this->leaveReview($seed, $owner, 3, service: 3, employee: 3, phone: '0750 123 '.$phone);
        }

        $after = reviewListQueries($list);

        /*
         * Five: the reviews, all of their ratings in one go, and the branch,
         * service and employee names in one query each. None of them is per
         * row, which is the whole claim (§52).
         */
        expect($baseline)->toBe($after)
            ->and($baseline)->toBeLessThanOrEqual(6);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
