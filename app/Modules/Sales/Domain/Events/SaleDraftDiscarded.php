<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

/**
 * A draft was discarded — and deleted, with its lines and adjustments.
 *
 * Carries the UUID, because the row is already gone. Dispatched synchronously
 * inside the discard transaction so a module that applied a benefit to the
 * draft reverses it in the same commit (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md
 * §8).
 */
final readonly class SaleDraftDiscarded
{
    public function __construct(public string $saleUuid) {}
}
