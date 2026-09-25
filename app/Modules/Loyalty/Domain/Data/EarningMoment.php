<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Data;

use Carbon\CarbonImmutable;

/**
 * What Loyalty saw while a qualifying event's own transaction was still open —
 * captured there, recorded after it commits.
 *
 * Captured by READING only: the money's transaction must never carry a loyalty
 * write, and a loyalty read that fails must not fail the payment, so either
 * field may be null and the recorder falls back to reading again
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§1, 6).
 */
final readonly class EarningMoment
{
    public function __construct(
        public CarbonImmutable $at,
        public ?bool $ownsLoyalty,
        public ?int $versionId,
    ) {}
}
