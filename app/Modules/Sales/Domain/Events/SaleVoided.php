<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Events;

/**
 * A finalized sale was voided.
 *
 * Dispatched synchronously INSIDE the void transaction, after the void guards
 * allowed it. Higher modules give back what the sale consumed — redeemed
 * points, a package session — and end what it sold, in the same transaction
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §8).
 */
final readonly class SaleVoided
{
    public function __construct(public int $saleId) {}
}
