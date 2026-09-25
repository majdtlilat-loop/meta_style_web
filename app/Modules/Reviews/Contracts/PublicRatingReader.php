<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Contracts;

/**
 * The center's rating as its public site may show it: the AGGREGATE only.
 *
 * No review text, no reviewer, no per-employee score. A review's words become
 * public only through a consent or moderation flag, and none exists — so this
 * contract has no way to return them (docs/22-REVIEWS.md).
 */
interface PublicRatingReader
{
    /**
     * The same numbers the center sees: RatingSummary over every branch,
     * hidden reviews excluded.
     *
     * @return array{count: int, average: float|null, distribution: array<int, int>}
     */
    public function summary(): array;
}
