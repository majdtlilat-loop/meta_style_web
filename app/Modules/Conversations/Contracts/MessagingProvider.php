<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Contracts;

use App\Modules\Conversations\Domain\Data\InboundBatch;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Data\MessagingCapabilities;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Data\SendResult;
use App\Modules\Conversations\Domain\Data\WhatsAppCredentials;
use App\Modules\Conversations\Domain\Exceptions\WebhookRejected;

/**
 * One messaging provider, translated. No business rules live here.
 *
 * The same boundary Payments draws around a gateway, for the same reasons: an
 * adapter speaks the provider's wire format and reports FACTS; the Actions
 * above decide what those facts mean (docs/25-WHATSAPP.md §5).
 *
 * ## Why the interface exists with a real implementation behind it
 *
 * Meta's Cloud API is the selected provider and `MetaWhatsAppCloudProvider` is
 * a genuine adapter against its published contract — so this is not a
 * speculative abstraction. It is the statement of what the rest of the system
 * is allowed to assume about ANY provider, which is what stops Meta's payload
 * shapes from leaking into the conversation domain. A `wamid`, an `entry[]`
 * array and an `X-Hub-Signature-256` header appear in exactly one class.
 *
 * ## What an adapter must never do
 *
 * - accept a sender identity from anywhere except a VERIFIED envelope;
 * - take a base URL, host or path from tenant data — the destination is
 *   platform configuration, so a center can never aim an outbound request at an
 *   address of their choosing (§4, SSRF);
 * - log a request body, a response body, an Authorization header or a
 *   signature;
 * - return anything secret in a result object;
 * - report a send as succeeded when it did not observe the outcome. That is
 *   what {@see SendResult::unknown()} is for, and inventing certainty there is
 *   how a customer gets the same message twice (§9).
 */
interface MessagingProvider
{
    /** The registry code stored on accounts: `meta_cloud`. */
    public function code(): string;

    public function displayName(): string;

    public function capabilities(): MessagingCapabilities;

    /**
     * Credential field names a manager must supply, in order.
     *
     * @return list<string>
     */
    public function credentialFields(): array;

    /**
     * Answers the provider's webhook-registration handshake.
     *
     * Meta performs a GET carrying `hub.mode`, `hub.verify_token` and
     * `hub.challenge`; the endpoint proves it is the one the center configured
     * by echoing the challenge back — but ONLY when the token matches.
     *
     * @return string|null the value to echo, or null to refuse. Null must be
     *                     answered with a flat 403 that says nothing about
     *                     which part was wrong.
     */
    public function verificationChallenge(WhatsAppCredentials $credentials, InboundEnvelope $envelope): ?string;

    /**
     * Verifies the notification's signature. TRUE means the bytes are the
     * provider's.
     *
     * Separate from {@see parse()} on purpose: verification must be able to
     * happen, and to FAIL, before anything in the body has been read — before
     * a customer is resolved, before a conversation is touched, before the AI
     * is reachable (§8).
     */
    public function verifySignature(WhatsAppCredentials $credentials, InboundEnvelope $envelope): bool;

    /**
     * Reads messages and delivery statuses out of a VERIFIED notification.
     *
     * Callers must not reach this without {@see verifySignature()} having
     * returned true. It returns everything the batch contained, because one
     * notification can carry several of each.
     *
     * @throws WebhookRejected when the payload is malformed beyond use
     */
    public function parse(InboundEnvelope $envelope): InboundBatch;

    /**
     * Sends one message.
     *
     * Must not throw for an ordinary provider refusal — that is
     * {@see SendResult::failed()}. Must return {@see SendResult::unknown()}
     * whenever the outcome was not observed.
     */
    public function send(WhatsAppCredentials $credentials, OutboundMessage $message): SendResult;
}
