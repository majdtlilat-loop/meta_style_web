<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Events;

/**
 * The provider explicitly refused an outbound message.
 *
 * Only a REFUSAL — a `failed`, where the provider answered and said no. An
 * `unknown` outcome deliberately does not raise this: it may well have been
 * delivered, and alerting staff to "we could not tell" on every transient
 * network blip would train them to ignore the alert that means a center's
 * WhatsApp integration is actually broken (docs/25-WHATSAPP.md §§9, 13).
 *
 * An `unknown` is still visible — the message row carries the state and the
 * staff inbox shows it — it simply does not page anybody.
 */
final readonly class ProviderSendFailed
{
    public function __construct(
        public string $conversationUuid,
        public ?int $branchId,
        /** A safe provider code, never a raw provider message or a token. */
        public string $failureCode,
    ) {}
}
