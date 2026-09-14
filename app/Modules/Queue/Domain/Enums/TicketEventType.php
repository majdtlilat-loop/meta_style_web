<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Enums;

/**
 * What happened to a ticket, appended and never rewritten.
 *
 * ## Why a table and not a handful of columns
 *
 * "Called three times, held once, transferred to the laser desk, then served"
 * is a question the product has to answer — for the host reading a row, for the
 * announcement a television has to repeat, and for the wait-time figures a
 * later phase will want. A `called_at` column holds one of those calls
 * (docs/17-QUEUE.md §13, §17).
 *
 * ## Not the audit log
 *
 * Audit answers "who changed what, and were they allowed to". This answers
 * "what happened to this customer". They overlap and they are not substitutes:
 * the audit log is a security record, and an operational report must never be
 * built on one (docs/08-AUDIT-SECURITY.md).
 */
enum TicketEventType: string
{
    case Issued = 'issued';
    case Called = 'called';
    case Recalled = 'recalled';
    /** Called, no answer. The ticket goes on hold and stays recoverable. */
    case Skipped = 'skipped';
    case Held = 'held';
    case Resumed = 'resumed';
    case Transferred = 'transferred';
    /** The JourneyStage actually started — reported by Journey, not decided here. */
    case ServingStarted = 'serving_started';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case PriorityChanged = 'priority_changed';

    /**
     * The ones a display announces.
     *
     * A recall is a separate announcement on purpose: the customer did not hear
     * it the first time, and a screen that stayed silent because the number had
     * not changed would be useless (§13).
     *
     * @return list<string>
     */
    public static function announceableValues(): array
    {
        return [self::Called->value, self::Recalled->value];
    }
}
