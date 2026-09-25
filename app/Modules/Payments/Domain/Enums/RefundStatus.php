<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 *   pending → succeeded | failed
 *
 * `pending` only while a provider refund is being requested: it reserves the
 * amount so a second refund cannot take the same money. Cash and manual refunds
 * are created succeeded (docs/19-PAYMENTS.md §§25–26).
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Whether this refund's amount is held against its payment. */
    public function holdsPaymentAmount(): bool
    {
        return $this !== self::Failed;
    }
}
