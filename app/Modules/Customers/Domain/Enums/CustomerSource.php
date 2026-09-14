<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Enums;

/**
 * How a customer record came to exist.
 *
 * A small controlled catalog, not a marketing attribution engine. It answers
 * "who typed this in", which is what support needs when a record looks wrong —
 * not "which campaign did this person come from", which is a later module's
 * problem and a different shape entirely (docs/13-ROADMAP.md Phase 5 §2).
 */
enum CustomerSource: string
{
    /** Created by a member of staff, at the desk or on the phone. */
    case Staff = 'staff';

    /** Created to attach a guest booking or a walk-in visit. */
    case Guest = 'guest';

    /** The customer created their own account. */
    case SelfRegistration = 'self_registration';

    /** Brought in from a previous system. Import tooling is a later phase. */
    case Import = 'import';

    /**
     * Created by the booking flow — a guest who booked without an account.
     *
     * Phase 5 reserved this as `future_booking`, a placeholder nothing ever
     * wrote. Phase 6 renamed it to what it actually is: no stored row could
     * carry the old value, because no code path could produce one.
     */
    case Booking = 'booking';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
