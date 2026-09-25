<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Events;

/**
 * A customer left a review for a completed visit.
 *
 * Dispatched inside the submission transaction, so a listener's own writes
 * commit with the review or not at all. But the listeners that matter here —
 * the low-rating alert — schedule their work for AFTER that commit: a review is
 * the customer's truth and must never be lost because an internal alert could
 * not be written (docs/23-NOTIFICATIONS.md §11).
 *
 * Carries the score so a listener can decide without reading the review back,
 * and never the comment: a notification payload is not a place for
 * customer-authored text.
 */
final readonly class ReviewSubmitted
{
    public function __construct(
        public int $reviewId,
        public int $branchId,
        public int $overallRating,
        public ?int $customerId,
    ) {}
}
