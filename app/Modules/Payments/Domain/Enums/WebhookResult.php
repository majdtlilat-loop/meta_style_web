<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * What a received provider callback led to. Safe codes only — the reason, never
 * the payload (docs/19-PAYMENTS.md §22).
 */
enum WebhookResult: string
{
    /** The payment moved to the state the provider confirmed. */
    case Processed = 'processed';

    /** This exact event was already handled; nothing changed. */
    case Duplicate = 'duplicate';

    /** The payment was already final; the event could not change it. */
    case Ignored = 'ignored';

    /** The provider says it is still unpaid. */
    case StillPending = 'still_pending';

    /** Provider confirmed money that does not match the payment. NOT settled. */
    case AmountMismatch = 'amount_mismatch';
}
