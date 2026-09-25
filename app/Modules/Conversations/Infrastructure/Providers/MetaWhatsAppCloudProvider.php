<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Infrastructure\Providers;

use App\Kernel\Contact\PhoneNumber;
use App\Modules\Conversations\Contracts\MessagingProvider;
use App\Modules\Conversations\Domain\Data\InboundBatch;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Data\InboundMessage;
use App\Modules\Conversations\Domain\Data\InboundStatus;
use App\Modules\Conversations\Domain\Data\MessagingCapabilities;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Data\SendResult;
use App\Modules\Conversations\Domain\Data\WhatsAppCredentials;
use App\Modules\Conversations\Domain\Enums\DeliveryState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Meta's WhatsApp Cloud API, spoken directly.
 *
 * Built against Meta's published Cloud API and Graph Webhooks documentation,
 * read 2026-09-20 (docs/25-WHATSAPP.md §15 records exactly which facts were
 * verified). Every wire-format detail Meta owns — `wamid`, `entry[]`,
 * `X-Hub-Signature-256`, `hub.challenge`, `messaging_product` — appears in this
 * class and nowhere else in the codebase.
 *
 * ## Not proven against live credentials
 *
 * No Meta credentials exist in this environment, so this adapter has NOT
 * completed a real round trip. It is exercised by contract tests against
 * recorded response shapes. That limitation is stated in the Phase 13 report
 * rather than papered over: an integration that looks like it works is worse
 * than an honest "not verified yet", because it would be trusted with a
 * center's customers (§16).
 *
 * ## Destination is configuration
 *
 * The base URL and Graph version come from `config/whatsapp.php`. Nothing in a
 * tenant row can influence where a request goes — the only tenant-supplied part
 * of the URL is `phone_number_id`, which is path-segment-validated before use
 * (ADR-071).
 */
final class MetaWhatsAppCloudProvider implements MessagingProvider
{
    public const CODE = 'meta_cloud';

    public function __construct(
        private readonly Http $http,
        private readonly Config $config,
    ) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function displayName(): string
    {
        return 'WhatsApp Cloud API';
    }

    public function capabilities(): MessagingCapabilities
    {
        return new MessagingCapabilities(
            available: true,
            // Meta signs every notification with the app secret (§8).
            verifiesSignature: true,
            templates: true,
            /*
             * FALSE, and verified: Meta's `POST /{phone-number-id}/messages`
             * documents no caller-supplied idempotency key. There is therefore
             * no way to make a retry provably safe, which is exactly why an
             * ambiguous send stays `unknown` instead of being resent (§9).
             */
            idempotentSend: false,
            /*
             * FALSE, and verified: delivery is reported by CALLBACK. Meta
             * publishes no endpoint to read one message's status back on
             * demand, so an `unknown` is resolved by a later status callback or
             * by a human — never by a lookup this code invented (§11).
             */
            statusLookup: false,
        );
    }

    public function credentialFields(): array
    {
        return ['access_token', 'app_secret', 'verify_token'];
    }

    /**
     * Meta's webhook registration handshake.
     *
     * A GET carrying `hub.mode=subscribe`, `hub.verify_token` and
     * `hub.challenge`. The endpoint proves it is the one the center configured
     * by echoing the challenge — but only when the token matches.
     *
     * `hash_equals`, because this is a secret comparison; and an empty
     * configured token refuses everything rather than matching an empty
     * `hub.verify_token`, which is the one way this check can accidentally
     * succeed for an attacker.
     */
    public function verificationChallenge(WhatsAppCredentials $credentials, InboundEnvelope $envelope): ?string
    {
        $expected = $credentials->verifyToken();

        if ($expected === '') {
            return null;
        }

        if ($envelope->queryValue('hub.mode') !== 'subscribe') {
            return null;
        }

        $presented = $envelope->queryValue('hub.verify_token') ?? '';

        if (! hash_equals($expected, $presented)) {
            return null;
        }

        $challenge = $envelope->queryValue('hub.challenge');

        return $challenge === null || $challenge === '' ? null : $challenge;
    }

    /**
     * `X-Hub-Signature-256: sha256=<hex hmac-sha256(raw body, app secret)>`.
     *
     * Over the RAW BYTES. Decoding and re-encoding the JSON first would reorder
     * keys and renormalise escapes, and the signature over the result would
     * never match — which is every "signature verification is broken" bug in
     * this shape of integration (§8).
     */
    public function verifySignature(WhatsAppCredentials $credentials, InboundEnvelope $envelope): bool
    {
        if (! $credentials->canVerify()) {
            // No app secret configured: nothing can be verified, so nothing is
            // believed. Refusing here rather than comparing against an empty
            // key is what stops an empty signature from matching.
            return false;
        }

        $presented = $envelope->header('x-hub-signature-256');

        if ($presented === null || ! str_starts_with($presented, 'sha256=')) {
            return false;
        }

        $expected = hash_hmac('sha256', $envelope->rawBody, $credentials->appSecret());

        return hash_equals($expected, mb_substr($presented, 7));
    }

    /**
     * Reads every message and status out of a verified notification.
     *
     * Meta BATCHES: `entry[] → changes[] → value.{messages[],statuses[]}`. All
     * four levels are walked, because a parser that returned only the first
     * message would silently drop the rest of a busy minute while still
     * answering 200 — a loss nothing would ever report (§10).
     *
     * Anything unrecognised is SKIPPED rather than refused. Meta adds message
     * types (reactions, stickers, interactive replies) without warning, and a
     * notification containing one alongside a text message must not lose the
     * text message. What this adapter does not understand, it does not invent.
     */
    public function parse(InboundEnvelope $envelope): InboundBatch
    {
        $payload = $envelope->decoded();

        $messages = [];
        $statuses = [];

        foreach ($this->arrayAt($payload, 'entry') as $entry) {
            foreach ($this->arrayAt($entry, 'changes') as $change) {
                $value = is_array($change['value'] ?? null) ? $change['value'] : [];

                $phoneNumberId = $this->stringAt(
                    is_array($value['metadata'] ?? null) ? $value['metadata'] : [],
                    'phone_number_id',
                );

                foreach ($this->arrayAt($value, 'messages') as $message) {
                    $parsed = $this->message($message, $phoneNumberId);

                    if ($parsed instanceof InboundMessage) {
                        $messages[] = $parsed;
                    }
                }

                foreach ($this->arrayAt($value, 'statuses') as $status) {
                    $parsed = $this->status($status);

                    if ($parsed instanceof InboundStatus) {
                        $statuses[] = $parsed;
                    }
                }
            }
        }

        return new InboundBatch($messages, $statuses);
    }

    /**
     * `POST /{version}/{phone-number-id}/messages`.
     *
     * The three outcomes are kept strictly apart (§9):
     *
     *   2xx with a `wamid`   → `sent`. The provider named it.
     *   a refusal with a body → `failed`. Meta answered, and said no.
     *   anything else         → `unknown`. Including a timeout, a dropped
     *                           connection, a 5xx and a body that did not
     *                           parse. Meta may have delivered it.
     */
    public function send(WhatsAppCredentials $credentials, OutboundMessage $message): SendResult
    {
        if (! $credentials->canSend()) {
            return SendResult::failed('not_configured');
        }

        $to = PhoneNumber::parse($message->toPhone);

        if ($to === null) {
            return SendResult::failed('invalid_recipient');
        }

        try {
            $response = $this->http
                ->timeout($this->timeout())
                ->connectTimeout($this->connectTimeout())
                ->withToken($credentials->accessToken())
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint($credentials->phoneNumberId), $this->body($message, $to->e164));
        } catch (Throwable) {
            /*
             * The request may well have reached Meta. Nothing here knows, and
             * the exception message is not evidence either way — so the honest
             * answer is `unknown`, and generic code will not resend it.
             *
             * The throwable is deliberately NOT reported with its context: a
             * Guzzle exception can carry the request, and the request carries
             * the Authorization header.
             */
            return SendResult::unknown('transport');
        }

        if ($response->successful()) {
            $id = $this->stringAt(
                is_array($response->json('messages.0')) ? $response->json('messages.0') : [],
                'id',
            );

            // A 2xx without an id is not a success anybody can act on: there is
            // nothing to match a later status callback against.
            return $id === null ? SendResult::unknown('no_message_id') : SendResult::sent($id);
        }

        /*
         * A 5xx is Meta failing, not Meta refusing, and the message may still
         * have been queued on their side. Treated as `unknown` so it is never
         * blindly resent; a 4xx is a real refusal.
         */
        if ($response->serverError()) {
            return SendResult::unknown('provider_'.$response->status());
        }

        $code = $response->json('error.code');

        return SendResult::failed(is_scalar($code) ? 'meta_'.$code : 'http_'.$response->status());
    }

    /**
     * @return array<string, mixed>
     */
    private function body(OutboundMessage $message, string $to): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ];

        if (! $message->isTemplate()) {
            return $base + [
                'type' => 'text',
                'text' => [
                    // Link previews off: a customer message could contain a URL
                    // the center never meant to endorse, and a preview renders
                    // it as though they had.
                    'preview_url' => false,
                    'body' => $message->body,
                ],
            ];
        }

        $components = $message->parameters === [] ? [] : [[
            'type' => 'body',
            'parameters' => array_values(array_map(
                static fn (string $value): array => ['type' => 'text', 'text' => $value],
                $message->parameters,
            )),
        ]];

        return $base + [
            'type' => 'template',
            'template' => [
                'name' => $message->templateName,
                'language' => ['code' => $message->templateLocale],
                'components' => $components,
            ],
        ];
    }

    /**
     * One text message, or null for anything this adapter does not handle.
     */
    private function message(mixed $raw, ?string $phoneNumberId): ?InboundMessage
    {
        if (! is_array($raw) || $phoneNumberId === null) {
            return null;
        }

        // Phase 13 handles TEXT. A photo, a sticker or a location is a real
        // message a customer sent, and pretending to have read it would be
        // worse than the conversation router treating it as unhandled.
        if (($raw['type'] ?? null) !== 'text') {
            return null;
        }

        $id = $this->stringAt($raw, 'id');
        $from = $this->stringAt($raw, 'from');
        $text = $this->stringAt(is_array($raw['text'] ?? null) ? $raw['text'] : [], 'body');

        if ($id === null || $from === null || $text === null) {
            return null;
        }

        /*
         * Meta sends `wa_id` WITHOUT a leading `+`. Normalising through the
         * project's own E.164 rules is what makes this number comparable to a
         * customer's stored phone — the whole point of ADR-039 (§6).
         */
        $phone = PhoneNumber::parse('+'.ltrim($from, '+'));

        if ($phone === null) {
            return null;
        }

        return new InboundMessage(
            providerMessageId: $id,
            fromPhone: $phone->e164,
            phoneNumberId: $phoneNumberId,
            text: $text,
            sentAt: $this->timestamp($raw['timestamp'] ?? null),
        );
    }

    private function status(mixed $raw): ?InboundStatus
    {
        if (! is_array($raw)) {
            return null;
        }

        $id = $this->stringAt($raw, 'id');
        $status = $this->stringAt($raw, 'status');

        if ($id === null || $status === null) {
            return null;
        }

        $state = match ($status) {
            // `sent`, `delivered` and `read` are all confirmations that Meta
            // accepted and moved the message. They are not distinguished here:
            // the product question is "did it get there", and read receipts are
            // a customer's business rather than the center's.
            'sent', 'delivered', 'read' => DeliveryState::Sent,
            'failed' => DeliveryState::Failed,
            default => null,
        };

        if ($state === null) {
            return null;
        }

        $pricing = is_array($raw['pricing'] ?? null) ? $raw['pricing'] : [];
        $billable = $pricing['billable'] ?? null;

        return new InboundStatus(
            providerMessageId: $id,
            state: $state,
            at: $this->timestamp($raw['timestamp'] ?? null),
            // Meta's own words for what it charged. Recorded, never derived —
            // Meta Style invents no WhatsApp cost (§13).
            category: $this->stringAt($pricing, 'category'),
            billable: is_bool($billable) ? $billable : null,
            errorCode: $this->errorCode($raw),
        );
    }

    /**
     * @param  array<array-key, mixed>  $raw
     */
    private function errorCode(array $raw): ?string
    {
        $errors = $this->arrayAt($raw, 'errors');
        $first = $errors[0] ?? null;

        if (! is_array($first)) {
            return null;
        }

        $code = $first['code'] ?? null;

        return is_scalar($code) ? 'meta_'.$code : null;
    }

    /**
     * Meta sends UNIX seconds as a string.
     *
     * Falls back to now when it is unusable rather than refusing the whole
     * message: a timestamp is context, and losing a real customer message over
     * a malformed one would be the wrong trade.
     */
    private function timestamp(mixed $value): CarbonImmutable
    {
        if (is_numeric($value)) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        return CarbonImmutable::now()->utc();
    }

    private function endpoint(string $phoneNumberId): string
    {
        $base = rtrim((string) $this->config->get('whatsapp.providers.meta_cloud.base_url'), '/');
        $version = (string) $this->config->get('whatsapp.providers.meta_cloud.graph_version');

        /*
         * `phone_number_id` is tenant data going into a URL path, so it is
         * restricted to the digits Meta actually issues. Without this, a value
         * containing `../` or a host could redirect the request — the request
         * that carries the center's access token (ADR-071).
         */
        $segment = preg_match('/^[A-Za-z0-9_-]{1,64}$/', $phoneNumberId) === 1 ? $phoneNumberId : '';

        return $base.'/'.$version.'/'.$segment.'/messages';
    }

    private function timeout(): int
    {
        $timeout = $this->config->get('whatsapp.http.timeout', 10);

        return is_numeric($timeout) ? (int) $timeout : 10;
    }

    private function connectTimeout(): int
    {
        $timeout = $this->config->get('whatsapp.http.connect_timeout', 5);

        return is_numeric($timeout) ? (int) $timeout : 5;
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @return list<mixed>
     */
    private function arrayAt(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<array-key, mixed>  $source
     */
    private function stringAt(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
