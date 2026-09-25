<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Reviews\Application\Actions\SubmitReview;
use App\Modules\Reviews\Application\PublicReview;
use App\Modules\Reviews\Domain\Data\RatingInput;
use App\Modules\Reviews\Domain\Data\ReviewSubmission;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use App\Modules\Reviews\Domain\Exceptions\ReviewFailed;
use App\Modules\Reviews\Domain\Models\Review;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer's review page.
 *
 * Resolved from the center's public key (`public.tenant`) and the invitation's
 * 256-bit secret. NO authentication middleware, ever: a customer arriving from
 * a printed link or a QR code has no session and no token, and most of them
 * have no account at all (ADR-036, docs/22-REVIEWS.md §21).
 *
 * The page shows the center, the branch, the date and what was performed. It
 * links nowhere internal, and there is no way from here to any other visit.
 *
 * ## Every refusal looks the same
 *
 * Unknown, revoked, expired and not-eligible are one 404. The single exception
 * is a link that has already been used, which gets its thank-you page: whoever
 * is holding it has already proved they had it, so there is nothing left for
 * them to learn (§46).
 */
final class PublicReviewPageController extends Controller
{
    public function __invoke(string $center, string $token, PublicReview $reviews): View
    {
        unset($center);

        $invitation = $reviews->invitation($token);

        if ($reviews->isSpent($invitation)) {
            return view('reviews.public-done');
        }

        $form = $invitation === null ? null : $reviews->form($invitation);

        if ($form === null) {
            throw new NotFoundHttpException;
        }

        return view('reviews.public', ['form' => $form]);
    }

    /**
     * Records the review and shows the thank-you page.
     *
     * The rating inputs arrive as `service[<stage uuid>]` and
     * `employee[<stage uuid>]`. Nothing here decides what those uuids MEAN —
     * the Action resolves every target from the stage itself, so a crafted form
     * cannot rate a service that was skipped or an employee who was not there
     * (§§11–12).
     */
    public function submit(Request $request, string $center, string $token, PublicReview $reviews, SubmitReview $submit): RedirectResponse|View
    {
        $validated = $request->validate([
            'overall' => ['required', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
            'comment' => ['nullable', 'string', 'max:'.Review::MAX_COMMENT_LENGTH],
            'service' => ['nullable', 'array'],
            'service.*' => ['nullable', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
            'employee' => ['nullable', 'array'],
            'employee.*' => ['nullable', 'integer', 'between:'.Review::MIN_RATING.','.Review::MAX_RATING],
        ]);

        $submission = new ReviewSubmission(
            overallRating: (int) $validated['overall'],
            publicComment: isset($validated['comment']) && is_string($validated['comment']) ? $validated['comment'] : null,
            ratings: [
                ...$this->ratings($validated['service'] ?? null, RatingDimension::Service),
                ...$this->ratings($validated['employee'] ?? null, RatingDimension::Employee),
            ],
        );

        try {
            $submit->byToken($token, $submission);
        } catch (ReviewFailed $e) {
            if ($e->errorCode()->httpStatus() === 409) {
                // Already reviewed — a second press of the same button.
                return view('reviews.public-done');
            }

            if ($e->errorCode()->httpStatus() === 404) {
                throw new NotFoundHttpException;
            }

            return redirect()
                ->route('review.public', ['center' => $center, 'token' => $token])
                ->withInput()
                ->with('review_error', $e->getMessage());
        }

        return view('reviews.public-done');
    }

    /**
     * @param  mixed  $scores  the submitted map of stage uuid => 1–5
     * @return list<RatingInput>
     */
    private function ratings(mixed $scores, RatingDimension $dimension): array
    {
        if (! is_array($scores)) {
            return [];
        }

        $inputs = [];

        foreach ($scores as $stageUuid => $score) {
            // An optional dimension the customer left blank.
            if ($score === null || $score === '' || ! is_string($stageUuid)) {
                continue;
            }

            $inputs[] = new RatingInput($stageUuid, $dimension, (int) $score);
        }

        return $inputs;
    }
}
