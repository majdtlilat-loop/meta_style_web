<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * How money was, or is being, collected.
 *
 * Deliberately not a card type or a wallet brand. "Manual electronic" covers a
 * bank transfer, an FIB or FastPay transfer checked by hand, an external card
 * terminal: the center confirmed it; Meta Style did not verify it. Only
 * `gateway` is provider-verified (docs/19-PAYMENTS.md §6).
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case ManualElectronic = 'manual_electronic';
    case Gateway = 'gateway';

    /**
     * Whether this money goes into — or out of — a physical cash drawer. Only
     * cash does; nothing electronic changes what the drawer should hold.
     */
    public function movesDrawerCash(): bool
    {
        return $this === self::Cash;
    }

    /** Collected at the desk and settled on the spot, with no provider. */
    public function isDeskMethod(): bool
    {
        return $this !== self::Gateway;
    }
}
