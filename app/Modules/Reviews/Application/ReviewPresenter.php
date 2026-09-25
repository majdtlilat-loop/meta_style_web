<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Localization\TenantLocales;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use Illuminate\Database\Eloquent\Builder;

/**
 * A review as STAFF see it.
 *
 * ## An allow-list, named field by field
 *
 * Never a model dump minus a deny-list, which the next column somebody adds
 * defeats (docs/08-AUDIT-SECURITY.md). What is emitted is the review's own
 * uuid, its status, its scores, the customer's comment, the moderation trail,
 * and the NAMES of the branch and of what was rated.
 *
 * What is NOT emitted: the journey uuid, the invitation, the token digest, the
 * sale, the invoice, the customer's name or contact details, and every numeric
 * key. Staff read reviews from the branch they work in; they do not need a
 * handle on the visit behind one (docs/22-REVIEWS.md §23).
 *
 * ## Names resolved in bulk
 *
 * Branches, services and employees are loaded once per page and looked up from
 * a map. A presenter that resolved a name per rating would issue a query per
 * row, which is the exact shape the query-count test exists to catch (§52).
 */
final class ReviewPresenter
{
    public function __construct(private readonly TenantLocales $locales) {}

    /**
     * @param  list<Review>  $reviews
     * @param  array<int, list<ReviewRating>>  $ratings  keyed by review id
     * @return list<array<string, mixed>>
     */
    public function collection(array $reviews, array $ratings): array
    {
        $names = $this->names($reviews, $ratings);

        $presented = [];

        foreach ($reviews as $review) {
            $presented[] = $this->one($review, $ratings[(int) $review->getKey()] ?? [], $names);
        }

        return $presented;
    }

    /**
     * @param  list<ReviewRating>  $ratings
     * @param  array{branches: array<int, string>, services: array<int, string>, employees: array<int, string>, timezones?: array<int, string>}  $names
     * @return array<string, mixed>
     */
    public function one(Review $review, array $ratings, array $names): array
    {
        $detail = [];

        foreach ($ratings as $rating) {
            $detail[] = [
                'dimension' => $rating->dimension->value,
                'target' => $rating->dimension === RatingDimension::Service
                    ? ($names['services'][(int) $rating->service_id] ?? '')
                    : ($names['employees'][(int) $rating->employee_id] ?? ''),
                'rating' => $rating->rating,
            ];
        }

        return [
            'id' => $review->uuid,
            'status' => $review->status->value,
            'overall_rating' => $review->overall_rating,
            // The customer's own words, exactly as submitted. Every surface
            // that renders this escapes it; nothing stores markup (§56).
            'comment' => $review->public_comment,
            'submitted_at' => $review->submitted_at->toIso8601String(),
            'branch' => $names['branches'][(int) $review->branch_id] ?? '',
            // The branch's clock, so a screen can show when it was written in
            // the time the branch keeps — never the server's.
            'timezone' => $names['timezones'][(int) $review->branch_id] ?? null,
            'ratings' => $detail,
            'moderation' => $review->moderated_at === null ? null : [
                'at' => $review->moderated_at->toIso8601String(),
                'by' => $review->moderated_by_label,
                'reason' => $review->moderation_reason,
            ],
        ];
    }

    /**
     * Every branch, service and employee name this page needs, in three
     * queries — not three per row.
     *
     * @param  list<Review>  $reviews
     * @param  array<int, list<ReviewRating>>  $ratings
     * @return array{branches: array<int, string>, services: array<int, string>, employees: array<int, string>, timezones: array<int, string>}
     */
    public function names(array $reviews, array $ratings): array
    {
        $locale = $this->locales->resolve(app()->getLocale());

        $branchIds = [];
        $serviceIds = [];
        $employeeIds = [];

        foreach ($reviews as $review) {
            $branchIds[(int) $review->branch_id] = true;
        }

        foreach ($ratings as $rows) {
            foreach ($rows as $rating) {
                if ($rating->service_id !== null) {
                    $serviceIds[(int) $rating->service_id] = true;
                }

                if ($rating->employee_id !== null) {
                    $employeeIds[(int) $rating->employee_id] = true;
                }
            }
        }

        // Branch names and clocks in the same single query.
        $branches = [];
        $timezones = [];

        if ($branchIds !== []) {
            foreach (Branch::query()->whereIn('id', array_keys($branchIds))->get(['id', 'name', 'timezone']) as $branch) {
                $branches[(int) $branch->getKey()] = (string) $branch->name->get($locale);
                $timezones[(int) $branch->getKey()] = $branch->timezone;
            }
        }

        return [
            'branches' => $branches,
            'services' => $this->resolve(Service::query(), $serviceIds, $locale),
            'employees' => $this->resolve(Employee::query(), $employeeIds, $locale),
            'timezones' => $timezones,
        ];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  array<int, true>  $ids
     * @return array<int, string>
     */
    private function resolve(mixed $query, array $ids, string $locale): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach ($query->whereIn('id', array_keys($ids))->get() as $row) {
            /** @var object{name: mixed} $row */
            $name = $row->name;

            $names[(int) $row->getKey()] = is_object($name) && method_exists($name, 'get')
                ? (string) $name->get($locale)
                : (string) $name;
        }

        return $names;
    }
}
