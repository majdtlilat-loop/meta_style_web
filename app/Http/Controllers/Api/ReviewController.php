<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Reviews\Application\Actions\ManageReviewInvitation;
use App\Modules\Reviews\Application\Actions\ModerateReview;
use App\Modules\Reviews\Application\RatingSummary;
use App\Modules\Reviews\Application\ReviewInvitations;
use App\Modules\Reviews\Application\ReviewPresenter;
use App\Modules\Reviews\Application\ReviewsAccess;
use App\Modules\Reviews\Application\ReviewsQuery;
use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use App\Modules\Reviews\Domain\Models\Review;
use App\Modules\Reviews\Domain\Models\ReviewRating;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reviews for staff: the list, the summary, moderation, and a customer's link.
 *
 * Validate → one Action or query → present. Branch scope is applied by
 * `ReviewsQuery`, not by the filters a caller sends, so a review from a branch
 * this user may not work in is simply not found — a 404, never a 403
 * (docs/22-REVIEWS.md §§23, 50).
 *
 * The reissue endpoint is the only place a plaintext secret leaves the system,
 * and it returns it ONCE. There is deliberately no "show me the link" read: a
 * stored secret cannot be read back, and an endpoint that pretended otherwise
 * would have to store one (§5).
 */
final class ReviewController extends Controller
{
    public function index(Request $request, ReviewsQuery $query, ReviewPresenter $presenter, ReviewsAccess $access): JsonResponse
    {
        $user = $this->user($request);

        // Reading history survives a downgrade, so this is `authorize`, never
        // `ensure` (§18). Branch scope is applied by the query itself.
        $access->authorize($user, Permission::ReviewView, 0, 'You may not read reviews.');

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:'.implode(',', array_column(ReviewStatus::cases(), 'value'))],
            'rating' => ['nullable', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $page = $query->page($user, [
            'status' => $validated['status'] ?? null,
            'rating' => isset($validated['rating']) ? (int) $validated['rating'] : null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ]);

        return ApiResponse::data(['reviews' => $presenter->collection($page['reviews'], $page['ratings'])]);
    }

    public function show(Request $request, string $uuid, ReviewsQuery $query, ReviewPresenter $presenter, ReviewsAccess $access): JsonResponse
    {
        $user = $this->user($request);

        $access->authorize($user, Permission::ReviewView, 0, 'You may not read reviews.');

        $review = $query->find($user, $uuid);

        if (! $review instanceof Review) {
            // Out of this user's branch scope reads exactly like one that does
            // not exist (docs/08-AUDIT-SECURITY.md).
            return ApiResponse::error(ApiErrorCode::NotFound, 'That review does not exist.');
        }

        /** @var list<ReviewRating> $ratings */
        $ratings = $review->ratings()->orderBy('id')->get()->all();
        $grouped = [(int) $review->getKey() => $ratings];

        return ApiResponse::data([
            'review' => $presenter->one($review, $ratings, $presenter->names([$review], $grouped)),
        ]);
    }

    /**
     * The center's rating picture: count, average, the 1–5 spread, and the
     * per-service and per-employee averages — all from the one canonical read
     * model, so no two screens can disagree (§24).
     */
    public function summary(Request $request, RatingSummary $summary, ReviewsAccess $access): JsonResponse
    {
        $user = $this->user($request);

        $access->authorize($user, Permission::ReviewView, 0, 'You may not read reviews.');

        $branch = $request->integer('branch');

        if ($branch > 0 && ! $user->canAccessBranch($branch)) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That branch does not exist.');
        }

        $branch = $branch > 0 ? $branch : null;
        $scope = $user->branchScope();

        return ApiResponse::data(['summary' => [
            'overall' => $summary->overall($scope, $branch),
            'services' => $summary->byService($scope, $branch),
            'employees' => $summary->byEmployee($scope, $branch),
        ]]);
    }

    public function hide(Request $request, string $uuid, ReviewsQuery $query, ModerateReview $moderate, ReviewPresenter $presenter): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        return $this->moderated($request, $uuid, $query, $presenter,
            fn (Review $review, User $user): Review => $moderate->hide($review, $user, (string) $validated['reason']));
    }

    public function unhide(Request $request, string $uuid, ReviewsQuery $query, ModerateReview $moderate, ReviewPresenter $presenter): JsonResponse
    {
        return $this->moderated($request, $uuid, $query, $presenter,
            fn (Review $review, User $user): Review => $moderate->unhide($review, $user));
    }

    public function flag(Request $request, string $uuid, ReviewsQuery $query, ModerateReview $moderate, ReviewPresenter $presenter): JsonResponse
    {
        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        return $this->moderated($request, $uuid, $query, $presenter,
            fn (Review $review, User $user): Review => $moderate->flag($review, $user, $validated['reason'] ?? null));
    }

    /**
     * A fresh review link for a visit. The plaintext is returned ONCE and is
     * never stored, logged or audited (§5).
     */
    public function reissue(Request $request, string $journeyUuid, ManageReviewInvitation $manage, ReviewInvitations $invitations): JsonResponse
    {
        /** @var ServiceJourney|null $journey */
        $journey = ServiceJourney::query()->where('uuid', $journeyUuid)->first();

        if (! $journey instanceof ServiceJourney) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That visit does not exist.');
        }

        $secret = $manage->reissue($journey, $this->user($request));

        return ApiResponse::data(['url' => $invitations->url($secret)]);
    }

    /**
     * @param  callable(Review, User): Review  $act
     */
    private function moderated(Request $request, string $uuid, ReviewsQuery $query, ReviewPresenter $presenter, callable $act): JsonResponse
    {
        $user = $this->user($request);
        $review = $query->find($user, $uuid);

        if (! $review instanceof Review) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That review does not exist.');
        }

        $moderated = $act($review, $user);
        $names = $presenter->names([$moderated], []);

        return ApiResponse::data(['review' => $presenter->one($moderated, [], $names)]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
