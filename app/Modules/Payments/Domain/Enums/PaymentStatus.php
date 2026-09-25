<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * Where one collection attempt stands.
 *
 *   pending → succeeded | failed | cancelled
 *
 * Four states and three of them final. A succeeded payment never returns to
 * pending, and a refund never becomes a payment status: returning money is its
 * own record (docs/19-PAYMENTS.md §7).
 *
 * `pending` exists only for gateway payments. Cash and manual electronic
 * payments are created already succeeded — the money is in the center's hand.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Succeeded, self::Failed, self::Cancelled],
            self::Succeeded, self::Failed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Whether this payment's amount is held against its invoice: collected, or
     * still possibly on its way. A failed or cancelled payment holds nothing.
     */
    public function holdsInvoiceAmount(): bool
    {
        return $this === self::Pending || $this === self::Succeeded;
    }
}
