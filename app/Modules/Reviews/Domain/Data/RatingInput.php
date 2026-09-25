<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Data;

use App\Modules\Reviews\Domain\Enums\RatingDimension;

/**
 * One optional detail rating a customer gave, as it arrived.
 *
 * Anchored to a STAGE, never to a service or an employee directly: the stage is
 * the proof that the customer received that service from that person. Submission
 * resolves the target from the stage itself and ignores anything the caller
 * might have claimed about it, so a request can never rate a service that was
 * skipped, an employee who was merely booked, or anything at all from somebody
 * else's visit (docs/22-REVIEWS.md §§10–12).
 */
final readonly class RatingInput
{
    public function __construct(
        public string $stageUuid,
        public RatingDimension $dimension,
        public int $rating,
    ) {}
}
