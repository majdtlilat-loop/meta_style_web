<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

use App\Kernel\Audit\Enums\AuditSource;

/**
 * Which medium a conversation is happening over.
 *
 * ONE CASE TODAY, and a column rather than an assumption — the staff inbox, the
 * routing and the presenter all have to say which channel a thread is on, and
 * discovering later that "conversation" silently meant "WhatsApp" is a migration
 * across every one of them (docs/25-WHATSAPP.md §2).
 *
 * Unlike an unreachable status, an unused channel code costs nothing; unlike
 * `BookingSource`, none are declared ahead of time, because there is no second
 * channel whose name is already decided.
 */
enum ConversationChannel: string
{
    case WhatsApp = 'whatsapp';

    public function auditSource(): AuditSource
    {
        return match ($this) {
            self::WhatsApp => AuditSource::WhatsApp,
        };
    }
}
