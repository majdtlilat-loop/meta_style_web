<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Data;

/**
 * What a customer filled in, before anything has been checked.
 *
 * The overall score is REQUIRED — a review with no verdict on the visit would
 * leave the center's average a function of who happened to expand the optional
 * section. Everything else is optional: the per-service and per-employee rows,
 * and one public comment for the whole visit rather than a comment box beside
 * every line (docs/22-REVIEWS.md §§9–10).
 */
final readonly class ReviewSubmission
{
    /**
     * @param  list<RatingInput>  $ratings
     */
    public function __construct(
        public int $overallRating,
        public ?string $publicComment = null,
        public array $ratings = [],
    ) {}
}
