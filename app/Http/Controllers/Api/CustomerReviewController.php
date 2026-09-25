<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Application\PublicReview;
use App\Modules\Reviews\Domain\Data\RatingInput;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A signed-in customer's own review invitations.
 *
 * ## No secret is involved
 *
 * The capability token exists for GUESTS. A customer who is signed in is
 * already known, so their invitation is resolved by uuid against their own
 * customer record — which is precisely why a review invitation in their inbox
 * never has to carry a secret, and why nothing has to store one to reach them
 * (docs/22-REVIEWS.md §§5, 40).
 *
 * Somebody else's invitation is NOT FOUND. The guard is the only identity read
 * here; no customer id is ever taken from a request.
 */
final class CustomerReviewController extends Controller
{
    /**
     * The invitations this customer can still act on, and what may be rated.
     */
    public function index(Request $request, PublicReview $reviews): JsonResponse
    {
        return ApiResponse::data([
            'invitations' => $reviews->pendingFor((int) $this->account($request)->customer_id),
        ]);
    }

    public function submit(Request $request, string $uuid, SubmitReview $submit): JsonResponse
    {
        $validated = $request->validate([
            'overall' => ['required', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
            'comment' => ['nullable', 'string', 'max:'.Review::MAX_COMMENT_LENGTH],
            'ratings' => ['nullable', 'array'],
            'ratings.*.stage' => ['required', 'uuid'],
            'ratings.*.dimension' => ['required', 'string', 'in:service,employee'],
            'ratings.*.rating' => ['required', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
        ]);

        $ratings = [];

        /** @var list<array{stage: string, dimension: string, rating: int}> $rows */
        $rows = $validated['ratings'] ?? [];

        foreach ($rows as $row) {
            $ratings[] = new RatingInput(
                (string) $row['stage'],
                RatingDimension::from((string) $row['dimension']),
                (int) $row['rating'],
            );
        }

        $review = $submit->byCustomer($this->account($request), $uuid, new ReviewSubmission(
            overallRating: (int) $validated['overall'],
            publicComment: isset($validated['comment']) && is_string($validated['comment']) ? $validated['comment'] : null,
            ratings: $ratings,
        ));

        // Their own review back, and nothing about the center's internals.
        return ApiResponse::data(['review' => [
            'id' => $review->uuid,
            'overall_rating' => $review->overall_rating,
            'submitted_at' => $review->submitted_at->toIso8601String(),
        ]]);
    }

    private function account(Request $request): CustomerAccount
    {
        $account = $request->user('customer-api');

        if (! $account instanceof CustomerAccount) {
            abort(ApiErrorCode::Unauthenticated->httpStatus());
        }

        return $account;
    }
}
