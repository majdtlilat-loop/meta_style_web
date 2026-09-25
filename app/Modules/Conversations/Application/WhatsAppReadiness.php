<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Modules\Conversations\Contracts\MessagingProvider;
use App\Modules\Conversations\Domain\Enums\DeliveryState;
use App\Modules\Conversations\Domain\Enums\MessageDirection;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Conversations\Domain\Models\WhatsAppWebhookEvent;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Is the center's WhatsApp connection set up — from what Meta Style RECORDED.
 *
 * ## An honest check, not a connection test
 *
 * The messaging provider contract has no health-check call, and Meta's Cloud
 * API adapter has not been run against live credentials (docs/25-WHATSAPP.md
 * §16). Inventing a "test connection" request would be an integration nobody
 * verified. So this reads facts only: the stored credentials are present and
 * still decrypt, the phone number id has the shape the adapter will accept,
 * the provider is available on this installation, the webhook handshake or a
 * signed notification has been seen, the last send's recorded outcome, and
 * any recorded error. Nothing here calls a provider.
 *
 * ## Never a secret
 *
 * Credentials are decrypted only to ask "is each field there". What leaves
 * this class is booleans, provider codes, identifiers that are not secret
 * (`phone_number_id`) and timestamps — never a token, a secret, a digest of
 * one, or a raw provider message.
 *
 * Read-only: it changes nothing, sends nothing and repairs nothing.
 *
 * @phpstan-type ReadinessFacts array{
 *     provider_name: string|null,
 *     credential_fields: array<string, bool>,
 *     credentials_state: 'complete'|'incomplete'|'missing'|'unreadable',
 *     configured_at: string|null,
 *     configured_by: string|null,
 *     last_inbound_at: string|null,
 *     handshake_at: string|null,
 *     webhook_verified: bool,
 *     last_rejected_at: string|null,
 *     signature_failing: bool,
 *     last_outbound: array{state: string, at: string|null, code: string|null}|null,
 *     last_error: array{at: string|null, code: string}|null
 * }
 */
final class WhatsAppReadiness
{
    /** The shape the adapter accepts in a URL path segment (ADR-071). */
    private const PHONE_NUMBER_ID = '/^[A-Za-z0-9_-]{1,64}$/';

    /** Rejections that mean the stored secrets do not match what Meta signs with. */
    private const SIGNATURE_FAILURES = ['signature_invalid', 'account_unusable'];

    public function __construct(
        private readonly MessagingProviderRegistry $providers,
        private readonly ConversationsAccess $access,
    ) {}

    /**
     * The center's account — one per center (§4), the oldest if a second row
     * was ever written, exactly as the API reads it.
     */
    public function account(): ?WhatsAppAccount
    {
        /** @var WhatsAppAccount|null $account */
        $account = WhatsAppAccount::query()->orderBy('id')->first();

        return $account;
    }

    /**
     * The setup checklist and its verdict.
     *
     * `state`: `not_connected` (no account, or never given credentials),
     * `problem` (a check failed — `problem` names the first), `waiting`
     * (everything is in place but WhatsApp has not reached the webhook yet),
     * or `ready`.
     *
     * @param  ReadinessFacts|null  $facts  what {@see facts()} returned for this
     *                                      account, when the caller already has it
     * @return array{
     *     state: 'not_connected'|'problem'|'waiting'|'ready',
     *     problem: string|null,
     *     checks: list<array{key: string, state: 'ok'|'problem'|'pending'|'unknown', code: string|null}>
     * }
     */
    public function check(?WhatsAppAccount $account, ?array $facts = null): array
    {
        $channel = $this->access->channelEnabled();

        if (! $account instanceof WhatsAppAccount) {
            return [
                'state' => 'not_connected',
                'problem' => $channel ? null : 'channel_inactive',
                'checks' => [$this->item('channel', $channel ? 'ok' : 'problem', $channel ? null : 'channel_inactive')],
            ];
        }

        $facts ??= $this->facts($account);
        $provider = $this->provider($account);

        $checks = [
            $this->item('channel', $channel ? 'ok' : 'problem', $channel ? null : 'channel_inactive'),
            $provider instanceof MessagingProvider && $provider->capabilities()->available
                ? $this->item('provider', 'ok')
                : $this->item('provider', 'problem', 'provider_unavailable'),
            $this->phoneNumberCheck($account),
            $this->credentialsCheck($facts['credentials_state']),
            $account->enabled ? $this->item('enabled', 'ok') : $this->item('enabled', 'problem', 'account_disabled'),
            $this->webhookCheck($facts),
            $this->deliveryCheck($facts['last_outbound']),
        ];

        if ($facts['last_error'] !== null) {
            $checks[] = $this->item('provider_error', 'problem', 'provider_error');
        }

        $problem = null;
        $pending = false;

        foreach ($checks as $check) {
            if ($check['state'] === 'problem' && $problem === null) {
                $problem = $check['code'];
            }

            $pending = $pending || $check['state'] === 'pending';
        }

        $state = match (true) {
            $facts['credentials_state'] === 'missing' && $account->configured_at === null => 'not_connected',
            $problem !== null => 'problem',
            $pending => 'waiting',
            default => 'ready',
        };

        return ['state' => $state, 'problem' => $problem, 'checks' => $checks];
    }

    /**
     * Facts about the account for the status panels. Instants are ISO-8601
     * UTC strings; the screen puts them on a wall clock.
     *
     * @return ReadinessFacts
     */
    public function facts(WhatsAppAccount $account): array
    {
        $provider = $this->provider($account);
        $fields = $provider instanceof MessagingProvider ? $provider->credentialFields() : [];

        $stored = $account->readableCredentials();
        $unreadable = $stored === null && $account->getRawOriginal('credentials') !== null;

        $present = [];

        foreach ($fields as $field) {
            $present[$field] = $stored !== null && trim($stored[$field] ?? '') !== '';
        }

        $credentialsState = match (true) {
            $unreadable => 'unreadable',
            $stored === null => 'missing',
            $fields !== [] && in_array(false, $present, true) => 'incomplete',
            default => 'complete',
        };

        /** @var WhatsAppWebhookEvent|null $verified */
        $verified = WhatsAppWebhookEvent::query()
            ->where('whatsapp_account_id', $account->getKey())
            ->where('signature_verified', true)
            ->orderByDesc('id')
            ->first(['id', 'received_at']);

        /** @var WhatsAppWebhookEvent|null $rejected */
        $rejected = WhatsAppWebhookEvent::query()
            ->where('whatsapp_account_id', $account->getKey())
            ->where('signature_verified', false)
            ->whereIn('error_code', self::SIGNATURE_FAILURES)
            ->orderByDesc('id')
            ->first(['id', 'received_at']);

        $handshake = WhatsAppWebhookEvent::query()
            ->where('whatsapp_account_id', $account->getKey())
            ->where('kind', 'handshake')
            ->orderByDesc('id')
            ->value('received_at');

        return [
            'provider_name' => $provider?->displayName(),
            'credential_fields' => $present,
            'credentials_state' => $credentialsState,
            'configured_at' => $this->iso($account->configured_at),
            'configured_by' => $account->configured_by_label,
            'last_inbound_at' => $this->iso($account->last_inbound_at),
            'handshake_at' => $this->iso($handshake),
            'webhook_verified' => $verified instanceof WhatsAppWebhookEvent || $account->last_inbound_at !== null,
            'last_rejected_at' => $this->iso($rejected?->received_at),
            // Failing NOW: the latest rejection is newer than anything that
            // verified. An old burst followed by good traffic is history.
            'signature_failing' => $rejected instanceof WhatsAppWebhookEvent
                && (! $verified instanceof WhatsAppWebhookEvent || $rejected->id > $verified->id),
            'last_outbound' => $this->lastOutbound($account),
            'last_error' => $account->last_error_code === null ? null : [
                'at' => $this->iso($account->last_error_at),
                'code' => $account->last_error_code,
            ],
        ];
    }

    /**
     * The last message this account sent, as recorded: `sent`, `failed`,
     * `unknown` or `pending`, and the provider's code for a failure.
     *
     * @return array{state: string, at: string|null, code: string|null}|null
     */
    private function lastOutbound(WhatsAppAccount $account): ?array
    {
        /** @var Message|null $message */
        $message = Message::query()
            ->where('direction', MessageDirection::Outbound)
            ->whereIn('conversation_id', Conversation::query()->select('id')->where('whatsapp_account_id', $account->getKey()))
            ->orderByDesc('id')
            ->first(['id', 'delivery_state', 'failure_code', 'created_at']);

        if (! $message instanceof Message) {
            return null;
        }

        return [
            'state' => $message->delivery_state instanceof DeliveryState ? $message->delivery_state->value : 'pending',
            'at' => $this->iso($message->created_at),
            'code' => $message->delivery_state === DeliveryState::Failed ? $message->failure_code : null,
        ];
    }

    private function provider(WhatsAppAccount $account): ?MessagingProvider
    {
        return $this->providers->has($account->provider) ? $this->providers->get($account->provider) : null;
    }

    /**
     * @return array{key: string, state: 'ok'|'problem'|'pending'|'unknown', code: string|null}
     */
    private function phoneNumberCheck(WhatsAppAccount $account): array
    {
        $id = $account->phone_number_id;

        if ($id === null || $id === '') {
            return $this->item('phone_number_id', 'problem', 'phone_number_id_missing');
        }

        return preg_match(self::PHONE_NUMBER_ID, $id) === 1
            ? $this->item('phone_number_id', 'ok')
            : $this->item('phone_number_id', 'problem', 'phone_number_id_invalid');
    }

    /**
     * @return array{key: string, state: 'ok'|'problem'|'pending'|'unknown', code: string|null}
     */
    private function credentialsCheck(string $state): array
    {
        return match ($state) {
            'complete' => $this->item('credentials', 'ok'),
            'unreadable' => $this->item('credentials', 'problem', 'credentials_unreadable'),
            'incomplete' => $this->item('credentials', 'problem', 'credentials_incomplete'),
            default => $this->item('credentials', 'problem', 'credentials_missing'),
        };
    }

    /**
     * @param  array{webhook_verified: bool, signature_failing: bool}  $facts
     * @return array{key: string, state: 'ok'|'problem'|'pending'|'unknown', code: string|null}
     */
    private function webhookCheck(array $facts): array
    {
        if ($facts['signature_failing']) {
            return $this->item('webhook', 'problem', 'signature_failures');
        }

        return $facts['webhook_verified']
            ? $this->item('webhook', 'ok')
            : $this->item('webhook', 'pending', 'webhook_not_verified');
    }

    /**
     * A refusal is a problem; `unknown` and `pending` are shown as recorded
     * and judged by nobody — Meta may well have delivered them (§9).
     *
     * @param  array{state: string, at: string|null, code: string|null}|null  $last
     * @return array{key: string, state: 'ok'|'problem'|'pending'|'unknown', code: string|null}
     */
    private function deliveryCheck(?array $last): array
    {
        return match ($last['state'] ?? null) {
            null => $this->item('delivery', 'unknown', 'nothing_sent'),
            DeliveryState::Sent->value => $this->item('delivery', 'ok'),
            DeliveryState::Failed->value => $this->item('delivery', 'problem', 'last_send_failed'),
            default => $this->item('delivery', 'unknown', 'last_send_unknown'),
        };
    }

    /**
     * @param  'ok'|'problem'|'pending'|'unknown'  $state
     * @return array{key: string, state: 'ok'|'problem'|'pending'|'unknown', code: string|null}
     */
    private function item(string $key, string $state, ?string $code = null): array
    {
        return ['key' => $key, 'state' => $state, 'code' => $code];
    }

    private function iso(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toImmutable()->utc()->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value, 'UTC')->utc()->toIso8601String();
        }

        return null;
    }
}
