<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

/**
 * A benefit held on a draft nobody came back to was released.
 *
 * Dispatched synchronously INSIDE the transaction that removes the adjustment,
 * exactly like a void: the module that granted it gives it back in the same
 * transaction, so a customer's points, sessions or membership uses can never be
 * held by an abandoned cart for ever
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §7).
 *
 * `sourceType` and `sourceReference` are the opaque pair the granting module
 * gave Sales; Sales never interprets them.
 */
final readonly class SaleBenefitReleased
{
    public function __construct(
        public int $saleId,
        public string $sourceType,
        public string $sourceReference,
    ) {}
}
