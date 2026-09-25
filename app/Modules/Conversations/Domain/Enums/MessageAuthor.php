<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

/**
 * Who produced a message.
 *
 * The field a center looks at when a customer complains about something they
 * were told. "The bot said it" and "one of my staff said it" are completely
 * different conversations to have, and a thread that cannot tell them apart is
 * useless for exactly the case it most needs to serve
 * (docs/25-WHATSAPP.md §3).
 */
enum MessageAuthor: string
{
    case Customer = 'customer';
    case Ai = 'ai';
    case Staff = 'staff';

    /**
     * The application speaking for itself: a hand-off acknowledgement, a
     * closing notice. Never a business answer — anything that states a fact
     * about a booking, a price or a benefit comes from a tool result through
     * the AI, or from a person. The one exception is an approved-template
     * NOTICE (a guest's booking confirmation, docs/25-WHATSAPP.md §22), which
     * is rendered field by field from the booking record itself, never
     * generated.
     */
    case System = 'system';

    public function isFromCenter(): bool
    {
        return $this !== self::Customer;
    }
}
