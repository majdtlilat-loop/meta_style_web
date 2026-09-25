<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

/**
 * What became of one business-initiated WhatsApp notice
 * (docs/25-WHATSAPP.md §22).
 *
 * The delivery half mirrors {@see DeliveryState} — and inherits its rule:
 * `unknown` and a `pending` whose process died are NEVER retried by generic
 * code, because Meta's send takes no idempotency key and a retry would be a
 * second copy at the customer's phone. Only a `failed` — the provider answered
 * and said no, or nothing was sent at all — may be attempted again.
 *
 * `skipped` is the policy saying no before anything was sent: the center
 * switched the notice off, the customer has no usable number or opted out of
 * booking messages, the channel is not connected, or no approved template is
 * configured. The row's `reason` names which.
 */
enum NoticeStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case Skipped = 'skipped';

    public static function fromDelivery(DeliveryState $state): self
    {
        return match ($state) {
            DeliveryState::Sent => self::Sent,
            DeliveryState::Failed => self::Failed,
            DeliveryState::Unknown => self::Unknown,
            DeliveryState::Pending => self::Pending,
        };
    }

    /** May a later pass attempt this notice again? Only a refusal. */
    public function isRetrySafe(): bool
    {
        return $this === self::Failed;
    }
}
