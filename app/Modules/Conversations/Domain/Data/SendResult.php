<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

use App\Modules\Conversations\Domain\Enums\DeliveryState;

/**
 * What an adapter observed when it tried to send.
 *
 * An adapter reports; it never decides. In particular it must return
 * {@see unknown()} rather than guessing whenever it did not SEE an outcome —
 * a timeout, a dropped connection, a malformed answer. Turning that into
 * `failed` is how a customer receives the same message twice
 * (docs/25-WHATSAPP.md §9).
 */
final readonly class SendResult
{
    private function __construct(
        public DeliveryState $state,
        public ?string $providerMessageId = null,
        public ?string $failureCode = null,
    ) {}

    /** The provider accepted it and named it. */
    public static function sent(string $providerMessageId): self
    {
        return new self(DeliveryState::Sent, $providerMessageId);
    }

    /** The provider answered, and said no. Safe to act on. */
    public static function failed(string $failureCode): self
    {
        return new self(DeliveryState::Failed, null, $failureCode);
    }

    /**
     * The outcome was never observed. It may well have been delivered.
     *
     * NEVER retried by generic code, and surfaced to staff instead (§9).
     */
    public static function unknown(?string $failureCode = null): self
    {
        return new self(DeliveryState::Unknown, null, $failureCode);
    }
}
