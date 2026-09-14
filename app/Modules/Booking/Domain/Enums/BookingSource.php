<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Enums;

use App\Kernel\Audit\Enums\AuditSource;

/**
 * Which channel a booking came in through.
 *
 * ONE COLUMN, NOT A TABLE PER CHANNEL. Every future channel — the mobile app,
 * the white-label app, the WhatsApp bot, RAYAN — calls the same Booking Engine
 * and writes the same rows; all that differs is this code and the actor
 * (docs/13-ROADMAP.md Phase 6 §§15, 40).
 *
 * NEVER TAKEN FROM CLIENT INPUT. The source is derived server-side from the
 * endpoint or component that was reached, because `staff` is a privileged claim
 * — it is what tells an investigation that a member of staff made this booking
 * rather than a customer. A public endpoint that accepted `source=staff` in a
 * request body would let anyone forge that. `PublicBookingTest` posts exactly
 * that payload and asserts the stored source is `public_web`.
 */
enum BookingSource: string
{
    /** Reception, a manager, anyone signed in to the center. */
    case Staff = 'staff';

    /** The public electronic menu, booked by a guest. */
    case PublicWeb = 'public_web';

    /** A signed-in customer, booking for themselves. */
    case CustomerAccount = 'customer_account';

    /*
     * Below: declared, never written by Phase 6 code.
     *
     * Unlike an unreachable STATUS, an unreachable source code costs nothing
     * and buys something real — the codes are stable strings a later channel
     * adopts without a data migration, and having them here now is what stops
     * the WhatsApp adapter from inventing `whatsapp_bot` while a report
     * somewhere already groups on `whatsapp`.
     */

    case MobileApp = 'mobile_app';
    case WhiteLabelApp = 'white_label_app';
    case WhatsApp = 'whatsapp';
    case Rayan = 'rayan';

    /**
     * Where the audit trail should say this came from.
     *
     * Deliberately lossy: `AuditSource` describes the transport, and this
     * describes the product channel. Both are kept — the appointment row holds
     * the channel, the audit row holds the transport.
     */
    public function auditSource(): AuditSource
    {
        return match ($this) {
            self::Staff, self::PublicWeb, self::CustomerAccount, self::MobileApp, self::WhiteLabelApp => AuditSource::Web,
            self::WhatsApp => AuditSource::WhatsApp,
            self::Rayan => AuditSource::Ai,
        };
    }

    public function isPublic(): bool
    {
        return $this === self::PublicWeb;
    }
}
