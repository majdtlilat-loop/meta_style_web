<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Enums;

/**
 * Where a review invitation stands.
 *
 *     issued ──submit──▶ used
 *        │
 *        └──rotate/revoke──▶ revoked
 *
 * EXPIRY IS NOT A STATUS. It is `expires_at` compared to now, because a status
 * column would have to be written by something — a sweep — and an invitation
 * that is expired in fact but still `issued` in the table would be accepted by
 * whatever forgot to check the date. One source of truth, checked under the
 * lock at submission (docs/22-REVIEWS.md §6).
 *
 * `used` is terminal: one completed visit produces one review, and the token
 * that produced it stops working the moment it does.
 */
enum InvitationStatus: string
{
    case Issued = 'issued';
    case Used = 'used';
    case Revoked = 'revoked';
}
