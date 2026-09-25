<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Identity\Models\User;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff list of what customers said.
 *
 * ## Branch scope is applied here, not on the screen
 *
 * `BranchScope::applyTo()` narrows every query to the branches the signed-in
 * user may work in. A manager of Branch A cannot see Branch B's reviews by
 * changing a filter, editing a URL or calling the API directly, because the
 * filter is not what is protecting them (docs/22-REVIEWS.md §23, §50).
 *
 * ## Two queries for a page, never two per row
 *
 * The page is one query for the reviews and ONE more for all of their ratings,
 * grouped in PHP. The shape this avoids is the obvious one — a page that loads
 * each review's ratings, its service and its employee as it renders — which
 * issues a query per row and gets slower with every review a center collects
 * (§52).
 *
 * ## Days are the branch's days
 *
 * A plain `from` / `to` date is a branch-local calendar day, and `to` INCLUDES
 * that day ({@see ReviewDays}). A full timestamp is still compared as given.
 */
final class ReviewsQuery
{
    public const PAGE = 20;

    /**
     * One page of reviews, newest first, with their detail ratings attached.
     *
     * @param  array{branch?: int|null, status?: string|null, rating?: int|null, from?: string|null, to?: string|null, service?: int|null, employee?: int|null, customer?: int|null}  $filters
     * @return array{reviews: list<Review>, ratings: array<int, list<ReviewRating>>}
     */
    public function page(User $actor, array $filters = [], int $limit = self::PAGE, ?int $before = null): array
    {
        $query = $this->scoped($actor, $filters)->orderByDesc('id')->limit(max(1, min($limit, 100)));

        if ($before !== null) {
            $query->where('id', '<', $before);
        }

        /** @var list<Review> $reviews */
        $reviews = $query->get()->all();

        return ['reviews' => $reviews, 'ratings' => $this->ratingsFor($reviews)];
    }

    /**
     * One page of reviews OLDER than the review named by `$beforeUuid` — a
     * keyset cursor by uuid, so no auto-increment id ever reaches a screen's
     * state. An unknown or out-of-scope cursor starts from the newest.
     *
     * @param  array{branch?: int|null, status?: string|null, rating?: int|null, from?: string|null, to?: string|null, service?: int|null, employee?: int|null, customer?: int|null}  $filters
     * @return array{reviews: list<Review>, ratings: array<int, list<ReviewRating>>, has_more: bool}
     */
    public function pageBefore(User $actor, array $filters = [], ?string $beforeUuid = null, int $limit = self::PAGE): array
    {
        $limit = max(1, min($limit, 100));
        $query = $this->scoped($actor, $filters)->orderByDesc('id')->limit($limit + 1);

        if ($beforeUuid !== null && $beforeUuid !== '') {
            $cursor = $this->scoped($actor)->where('uuid', $beforeUuid)->value('id');

            if ($cursor !== null) {
                $query->where('id', '<', (int) $cursor);
            }
        }

        /** @var list<Review> $rows */
        $rows = $query->get()->all();
        $hasMore = count($rows) > $limit;
        $reviews = array_slice($rows, 0, $limit);

        return ['reviews' => $reviews, 'ratings' => $this->ratingsFor($reviews), 'has_more' => $hasMore];
    }

    /**
     * Whether this center has collected any review at all — for the locked
     * page after a downgrade: nothing collected, nothing to read (§18).
     */
    public function anyCollected(): bool
    {
        return Review::query()->exists();
    }

    /**
     * One review the actor is allowed to see, or null.
     */
    public function find(User $actor, string $uuid): ?Review
    {
        /** @var Review|null $review */
        $review = $this->scoped($actor)->where('uuid', $uuid)->first();

        return $review;
    }

    /**
     * @param  array{branch?: int|null, status?: string|null, rating?: int|null, from?: string|null, to?: string|null, service?: int|null, employee?: int|null, customer?: int|null}  $filters
     * @return Builder<Review>
     */
    private function scoped(User $actor, array $filters = []): Builder
    {
        /** @var Builder<Review> $query */
        $query = $actor->branchScope()->applyTo(Review::query());

        if (($filters['branch'] ?? null) !== null) {
            $query->where('branch_id', $filters['branch']);
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['rating'] ?? null) !== null) {
            $query->where('overall_rating', $filters['rating']);
        }

        // A review by one customer — for their page. The id comes from the
        // Customers read the screen already authorised, never from a request.
        if (($filters['customer'] ?? null) !== null) {
            $query->where('customer_id', $filters['customer']);
        }

        $this->applyDates($query, $filters['from'] ?? null, $filters['to'] ?? null);

        // Filtering by what was rated is a filter on the ratings, applied as a
        // sub-select so the page stays one query.
        foreach ([['service', 'service_id', RatingDimension::Service], ['employee', 'employee_id', RatingDimension::Employee]] as [$key, $column, $dimension]) {
            if (($filters[$key] ?? null) === null) {
                continue;
            }

            $query->whereIn('id', ReviewRating::query()
                ->select('review_id')
                ->where('dimension', $dimension->value)
                ->where($column, $filters[$key]));
        }

        return $query;
    }

    /**
     * Plain dates are branch-local days with an inclusive end; anything else
     * (a full timestamp from an API caller) is compared as given.
     *
     * @param  Builder<Review>  $query
     */
    private function applyDates(Builder $query, ?string $from, ?string $to): void
    {
        $days = ReviewDays::between($from, $to);

        $days->applyTo($query);

        if ($from !== null && $days->from === null) {
            $query->where('submitted_at', '>=', $from);
        }

        if ($to !== null && $days->to === null) {
            $query->where('submitted_at', '<=', $to);
        }
    }

    /**
     * Every rating of these reviews, in ONE query, grouped by review.
     *
     * @param  list<Review>  $reviews
     * @return array<int, list<ReviewRating>>
     */
    private function ratingsFor(array $reviews): array
    {
        if ($reviews === []) {
            return [];
        }

        $ids = array_map(static fn (Review $review): int => (int) $review->getKey(), $reviews);

        $grouped = [];

        foreach (ReviewRating::query()->whereIn('review_id', $ids)->orderBy('id')->get() as $rating) {
            $grouped[(int) $rating->review_id][] = $rating;
        }

        return $grouped;
    }
}
