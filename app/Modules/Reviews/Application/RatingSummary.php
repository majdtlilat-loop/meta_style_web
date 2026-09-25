<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Authorization\BranchScope;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The ONE place an average is computed.
 *
 * Every screen, every API and every export asks this; nothing adds up ratings
 * for itself. Two controllers each doing their own `avg()` is how a center ends
 * up with a dashboard and a report that disagree about its own score, and the
 * disagreement is always about which reviews were counted
 * (docs/22-REVIEWS.md §§13, 24).
 *
 * ## What counts
 *
 * `submitted` and `flagged`. NOT `hidden`. Flagging is a note asking staff to
 * look at something; hiding is the decision to take it out of the center's
 * public picture, and only the decision changes the number (§8).
 *
 * ## No stored averages
 *
 * There is no `average_rating` column on a branch, a service or an employee.
 * A stored average is a second source of truth that has to be recomputed on
 * every submission, every hide and every unhide, and is wrong in between.
 * These are grouped aggregates over indexed columns; caching is a later,
 * deliberate decision with its own invalidation rules (§25).
 *
 * ## One query per answer
 *
 * `byService()` and `byEmployee()` each return every target in ONE grouped
 * query. A page that called a per-employee average in a loop would issue a
 * query per row and get slower with every hire (§52).
 */
final class RatingSummary
{
    /** Averages are shown to one decimal, decided once, here. */
    public const PRECISION = 1;

    /**
     * The center's (or one branch's) overall picture.
     *
     * @return array{count: int, average: float|null, distribution: array<int, int>}
     */
    public function overall(BranchScope $scope, ?int $branchId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?ReviewDays $days = null): array
    {
        /** @var list<object{rating: int, total: int}> $rows */
        $rows = $this->visible($scope, $branchId, $from, $to, $days)
            ->getQuery()
            ->select('overall_rating as rating')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('overall_rating')
            ->get()
            ->all();

        $distribution = array_fill_keys(range(Review::MIN_RATING, Review::MAX_RATING), 0);
        $count = 0;
        $sum = 0;

        foreach ($rows as $row) {
            $rating = (int) $row->rating;
            $total = (int) $row->total;

            $distribution[$rating] = $total;
            $count += $total;
            $sum += $rating * $total;
        }

        return [
            'count' => $count,
            'average' => $count === 0 ? null : round($sum / $count, self::PRECISION),
            'distribution' => $distribution,
        ];
    }

    /**
     * Every rated service, in one query.
     *
     * @return array<int, array{count: int, average: float}> keyed by service id
     */
    public function byService(BranchScope $scope, ?int $branchId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?ReviewDays $days = null): array
    {
        return $this->byTarget(RatingDimension::Service, 'service_id', $scope, $branchId, $from, $to, $days);
    }

    /**
     * Every rated employee, in one query — the people who actually performed
     * the work, because that is what a rating row is anchored to (§11).
     *
     * @return array<int, array{count: int, average: float}> keyed by employee id
     */
    public function byEmployee(BranchScope $scope, ?int $branchId = null, ?CarbonInterface $from = null, ?CarbonInterface $to = null, ?ReviewDays $days = null): array
    {
        return $this->byTarget(RatingDimension::Employee, 'employee_id', $scope, $branchId, $from, $to, $days);
    }

    /**
     * @return array<int, array{count: int, average: float}>
     */
    private function byTarget(RatingDimension $dimension, string $column, BranchScope $scope, ?int $branchId, ?CarbonInterface $from, ?CarbonInterface $to, ?ReviewDays $days = null): array
    {
        /** @var list<object{target: int|null, total: int, sum: int}> $rows */
        $rows = ReviewRating::query()
            ->getQuery()
            ->select($column.' as target')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(rating) as sum')
            ->where('dimension', $dimension->value)
            ->whereNotNull($column)
            ->whereIn('review_id', $this->visible($scope, $branchId, $from, $to, $days)->getQuery()->select('id'))
            ->groupBy($column)
            ->get()
            ->all();

        $summary = [];

        foreach ($rows as $row) {
            if ($row->target === null) {
                continue;
            }

            $total = (int) $row->total;

            $summary[(int) $row->target] = [
                'count' => $total,
                'average' => round(((int) $row->sum) / $total, self::PRECISION),
            ];
        }

        return $summary;
    }

    /**
     * The reviews a summary is built from. Every caller goes through here, so
     * "what counts" is decided once.
     *
     * @return Builder<Review>
     */
    private function visible(BranchScope $scope, ?int $branchId, ?CarbonInterface $from, ?CarbonInterface $to, ?ReviewDays $days = null): Builder
    {
        $query = $scope->applyTo(Review::query())
            ->whereIn('status', ReviewStatus::visibleValues());

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        if ($from !== null) {
            $query->where('submitted_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('submitted_at', '<=', $to);
        }

        // Branch-local calendar days, the last one included (§24).
        if ($days !== null) {
            $days->applyTo($query);
        }

        return $query;
    }
}
