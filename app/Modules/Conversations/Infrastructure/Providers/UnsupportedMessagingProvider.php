<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Infrastructure\Providers;

use App\Modules\Conversations\Contracts\MessagingProvider;
use App\Modules\Conversations\Domain\Data\InboundBatch;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Data\MessagingCapabilities;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Data\SendResult;
use App\Modules\Conversations\Domain\Data\WhatsAppCredentials;

/**
 * A messaging provider Meta Style has NOT implemented.
 *
 * Kept for the same reason `Payments\Infrastructure\Providers\UnsupportedProvider`
 * is: a provider that is listed but refuses everything is honest, and a
 * fabricated integration is not. It is also what a stored `provider` code
 * resolves to if a row survives an adapter being withdrawn — so the account
 * stops working loudly rather than resolving to something else.
 *
 * Phase 13 registers no unsupported providers: Meta Cloud is the selected
 * provider and is implemented. This exists so that adding a second one is an
 * adapter plus a registry line, never a change to the surrounding code
 * (docs/25-WHATSAPP.md §5).
 */
final class UnsupportedMessagingProvider implements MessagingProvider
{
    public function __construct(
        private readonly string $code,
        private readonly string $displayName,
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function capabilities(): MessagingCapabilities
    {
        return MessagingCapabilities::unavailable();
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function verificationChallenge(WhatsAppCredentials $credentials, InboundEnvelope $envelope): ?string
    {
        return null;
    }

    public function verifySignature(WhatsAppCredentials $credentials, InboundEnvelope $envelope): bool
    {
        // Never. An unimplemented provider cannot vouch for anything, and
        // returning true "because there is nothing to check" would make this
        // the easiest way into the system.
        return false;
    }

    public function parse(InboundEnvelope $envelope): InboundBatch
    {
        return new InboundBatch;
    }

    public function send(WhatsAppCredentials $credentials, OutboundMessage $message): SendResult
    {
        // `failed`, not `unknown`: nothing was attempted, so there is no
        // ambiguity to preserve (§9).
        return SendResult::failed('provider_unsupported');
    }
}
