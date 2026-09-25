<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * Who started the payment: the desk, or the customer from their invoice link.
 * Stored, never inferred.
 */
enum PaymentSource: string
{
    case Desk = 'desk';
    case PublicLink = 'public_link';
}
