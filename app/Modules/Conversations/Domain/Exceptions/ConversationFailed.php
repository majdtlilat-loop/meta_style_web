<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Exceptions;

use RuntimeException;

/**
 * A conversation operation was refused for a business reason.
 *
 * Staff-facing, unlike {@see WebhookRejected}: these messages are read by a
 * member of the center's team in their own inbox, so they say what is wrong.
 * They still never name a customer, a phone number or another center.
 */
final class ConversationFailed extends RuntimeException
{
    public static function policy(string $message): self
    {
        return new self($message);
    }

    /**
     * The AI may not speak while a human holds the thread, or while it is
     * closed (docs/25-WHATSAPP.md §12).
     */
    public static function aiNotAllowed(): self
    {
        return new self('The assistant cannot reply to this conversation.');
    }

    public static function notConfigured(): self
    {
        return new self('WhatsApp is not configured for this center.');
    }
}
