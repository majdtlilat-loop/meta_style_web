<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

use App\Modules\Conversations\Domain\Data\MessagingCapabilities;

/**
 * What happened to a message the center sent.
 *
 * ## `unknown` is the important one
 *
 * Three of these are ordinary. The fourth exists because of a failure mode that
 * every outbound integration has and most pretend not to: the request was sent,
 * and the application never learned the outcome. A connection dropped after the
 * bytes left, a gateway timed out, a worker was killed mid-call. Meta may well
 * have accepted the message and delivered it (docs/25-WHATSAPP.md §9).
 *
 * The wrong answers are both tempting:
 *
 *   calling it `failed`  invites a retry, and the retry sends the customer a
 *                        SECOND copy of a message they already received;
 *   calling it `sent`    claims a delivery nobody observed, and quietly loses
 *                        messages that never arrived.
 *
 * So it stays `unknown`, generic code NEVER blindly retries it, and it is
 * surfaced to staff — who can look at the thread and decide. Resolution is only
 * automatic where the adapter says the provider can be asked, through
 * {@see MessagingCapabilities}.
 */
enum DeliveryState: string
{
    /** Persisted, not yet handed to the provider (§10). */
    case Pending = 'pending';

    /** The provider accepted it and gave us an id. */
    case Sent = 'sent';

    /** The provider explicitly refused it. Safe to act on. */
    case Failed = 'failed';

    /** The outcome was never observed. Never auto-retried (§9). */
    case Unknown = 'unknown';

    /**
     * Whether a generic retry may resend this message.
     *
     * Only `failed` and `pending`: one was refused and never delivered, the
     * other never left. `unknown` is excluded by design — that is the entire
     * reason this enum has four cases instead of three.
     */
    public function isRetrySafe(): bool
    {
        return $this === self::Failed || $this === self::Pending;
    }

    /**
     * Whether staff should be shown this as something to look at.
     */
    public function needsAttention(): bool
    {
        return $this === self::Failed || $this === self::Unknown;
    }
}
