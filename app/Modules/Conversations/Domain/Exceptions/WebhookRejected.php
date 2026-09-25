<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Exceptions;

use RuntimeException;

/**
 * An inbound provider notification was refused before anything was believed.
 *
 * The message is a short machine REASON — `account_unknown`, `signature_invalid`,
 * `not_configured` — for the center's own webhook-event rows and for operators,
 * where the distinction is the whole diagnostic value.
 *
 * It is NEVER answered to the caller. The endpoint replies with a flat refusal
 * that says nothing about which check failed, because "no such account", "bad
 * signature" and "malformed body" are three different pieces of information a
 * forger would use to work out how far they got (docs/25-WHATSAPP.md §8).
 *
 * Same shape and same reasoning as `Payments\Domain\Exceptions\WebhookRejected`.
 */
final class WebhookRejected extends RuntimeException
{
    public static function because(string $safeReason): self
    {
        return new self($safeReason);
    }

    /**
     * The safe reason code, named so a caller cannot mistake it for something
     * presentable.
     */
    public function reason(): string
    {
        return $this->getMessage();
    }
}
