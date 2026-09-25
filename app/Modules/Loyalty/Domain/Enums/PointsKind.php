<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

/**
 * What a points movement was (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §3).
 *
 *   earn        money collected, or a completed visit                   in
 *   redeem      points spent as a discount at checkout                  out
 *   adjustment  a manager's reasoned correction                         in · out
 *   reversal    earned points taken back because money was refunded    out
 *               — or redeemed points returned because the discount was
 *               withdrawn, the draft discarded or the sale voided       in
 *   recovery    part of a later earning that settles a refund reversal
 *               the balance could not cover at the time                 out
 *   expiry      points that aged out before anything used them          out
 */
enum PointsKind: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case Adjustment = 'adjustment';
    case Reversal = 'reversal';
    case Recovery = 'recovery';
    case Expiry = 'expiry';
}
