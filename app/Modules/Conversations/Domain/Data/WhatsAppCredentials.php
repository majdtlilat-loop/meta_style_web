<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

use SensitiveParameter;

/**
 * A center's provider secrets, decrypted for the length of one adapter call.
 *
 * Built immediately before the adapter is called and discarded after. Never
 * stored, never logged, never audited, never presented — the same handling as
 * `Payments\Domain\Data\GatewayCredentials`, which this deliberately mirrors
 * (docs/25-WHATSAPP.md §4).
 *
 * ## The three secrets, and why each exists
 *
 * Verified against Meta's current Cloud API documentation (2026-09-20):
 *
 *   `access_token`  authorises outbound sends as this WhatsApp Business
 *                   number. A bearer token: whoever holds it can message that
 *                   center's customers in the center's name.
 *   `app_secret`    the key `X-Hub-Signature-256` is computed with. This is
 *                   what makes an inbound notification EVIDENCE rather than a
 *                   claim, and it is why a center that has not configured one
 *                   cannot receive messages at all.
 *   `verify_token`  a value the center chooses and gives to Meta, echoed back
 *                   during webhook setup so only whoever configured the
 *                   endpoint can complete the handshake.
 *
 * `phoneNumberId` sits beside them and is NOT a secret — it is an account
 * identifier that appears in every outbound URL and in every inbound
 * notification. It travels here so the adapter has everything one call needs in
 * one object.
 */
final readonly class WhatsAppCredentials
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        public string $accountUuid,
        public string $phoneNumberId,
        #[SensitiveParameter]
        private array $values,
    ) {}

    public function accessToken(): string
    {
        return $this->values['access_token'] ?? '';
    }

    public function appSecret(): string
    {
        return $this->values['app_secret'] ?? '';
    }

    public function verifyToken(): string
    {
        return $this->values['verify_token'] ?? '';
    }

    /**
     * Whether everything an inbound notification needs is present.
     *
     * Checked before verification rather than during it, so a center that has
     * half-configured their account gets a clean refusal instead of a signature
     * comparison against an empty string — which would be a comparison that can
     * accidentally SUCCEED if the sender also sends an empty signature.
     */
    public function canVerify(): bool
    {
        return $this->appSecret() !== '';
    }

    public function canSend(): bool
    {
        return $this->accessToken() !== '' && $this->phoneNumberId !== '';
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'accountUuid' => $this->accountUuid,
            'phoneNumberId' => $this->phoneNumberId,
            'values' => '[redacted]',
        ];
    }
}
