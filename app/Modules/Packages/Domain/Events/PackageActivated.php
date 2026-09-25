<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Events;

/**
 * A package of prepaid sessions the customer paid for is now usable.
 *
 * Raised by `ActivatePackages` when it writes the customer's package — which is
 * itself already after-commit work following a settled invoice (ADR-061). A
 * listener that tells the customer takes the same care: it schedules its write
 * for after this transaction commits, and is repaired by reconciliation if it
 * is lost. Identifiers only, and no listener may be able to undo the
 * activation.
 */
final readonly class PackageActivated
{
    public function __construct(
        public int $customerPackageId,
        public int $customerId,
    ) {}
}
