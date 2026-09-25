<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application\Actions;

use App\Kernel\Security\AttemptLimiter;
use App\Modules\Conversations\Application\ConversationRouter;
use App\Modules\Conversations\Application\ConversationsAccess;
use App\Modules\Conversations\Application\WhatsAppConnections;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Data\InboundMessage;
use App\Modules\Conversations\Domain\Data\InboundStatus;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Exceptions\WebhookRejected;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An inbound WhatsApp notification, from the door to the conversation.
 *
 * ## The order is mandatory (docs/25-WHATSAPP.md §8)
 *
 *     1. the center was resolved from its public key by `public.tenant`
 *     2. find the account by its public uuid — enabled or not
 *     3. load the adapter and decrypt the center's credentials
 *     4. VERIFY THE SIGNATURE
 *     5. only now: parse the body
 *     6. replay check, per item, by fingerprint
 *     7. rate limit, per account and per sender
 *     8. process
 *
 * Nothing before step 4 touches a customer, a conversation or the assistant.
 * That is the property the whole channel's security rests on: an unsigned
 * request cannot create a conversation, cannot resolve a phone number to a
 * customer, cannot reach a tool, and cannot spend a center's AI allowance.
 *
 * ## Why a rejected notification is still recorded
 *
 * A burst of failed signatures is the single most useful thing an operator can
 * see, and discarding them silently makes an attack indistinguishable from a
 * quiet afternoon. The row carries `signature_verified = false` and a reason
 * code; it carries no body (§18).
 *
 * ## Why it answers 200 to things it did not act on
 *
 * Meta retries anything that is not 2xx. A duplicate, an unsupported message
 * type or a rate-limited sender are all cases where retrying would achieve
 * nothing, so they are accepted and recorded. Only a notification that is not
 * BELIEVABLE is refused — and that refusal is deliberately indistinguishable
 * between causes.
 *
 * ## A channel that is switched off receives nothing
 *
 * Losing `whatsapp_booking`, or a manager turning the account off, stops the
 * center RECEIVING (docs/25-WHATSAPP.md §19): after the signature verifies, a
 * customer message is recorded as `ignored` and nothing else happens — no
 * conversation, no customer lookup, no assistant run. It is still answered
 * 200, because Meta would otherwise retry it forever. Delivery STATUSES for
 * messages already sent keep being applied: they change no conversation and
 * start nothing, and refusing them would leave outbound rows `pending` for
 * ever — the reason a disabled account is found at all (§8).
 */
final class ReceiveWhatsAppWebhook
{
    public function __construct(
        private readonly WhatsAppConnections $connections,
        private readonly ConversationRouter $router,
        private readonly AttemptLimiter $limiter,
        private readonly ConversationsAccess $access,
    ) {}

    /**
     * Answers Meta's webhook registration handshake.
     *
     * Separate entry point, and deliberately the only one that reads query
     * parameters. It proves the endpoint belongs to whoever configured the
     * center's Meta app, by echoing a challenge — and it establishes nothing,
     * touches no conversation and resolves no customer.
     *
     * @return string|null the challenge to echo, or null to refuse flatly
     */
    public function challenge(string $accountUuid, InboundEnvelope $envelope): ?string
    {
        $account = $this->connections->find($accountUuid);

        if (! $account instanceof WhatsAppAccount) {
            return null;
        }

        /*
         * Registering the webhook is how a provider connection is ACTIVATED.
         * A center that does not own the channel cannot activate one, so the
         * handshake is refused exactly like a wrong token (§19).
         */
        if (! $this->access->channelEnabled()) {
            return null;
        }

        try {
            $challenge = $this->connections->provider($account)
                ->verificationChallenge($this->connections->credentials($account), $envelope);
        } catch (ConversationFailed) {
            return null;
        }

        if ($challenge !== null) {
            $this->recordHandshake($account);
        }

        return $challenge;
    }

    /**
     * @return int how many items were acted on — for the controller's log only,
     *             never for the response body
     *
     * @throws WebhookRejected when the notification is not believable
     */
    public function __invoke(string $accountUuid, InboundEnvelope $envelope): int
    {
        $account = $this->connections->find($accountUuid);

        if (! $account instanceof WhatsAppAccount) {
            // No row to record against — there is no account. Nothing is
            // written, and the caller learns nothing.
            throw WebhookRejected::because('account_unknown');
        }

        try {
            $provider = $this->connections->provider($account);
            $credentials = $this->connections->credentials($account);
        } catch (ConversationFailed) {
            $this->record($account, 'unconfigured:'.Str::uuid()->toString(), 'rejected', false, errorCode: 'account_unusable');

            throw WebhookRejected::because('account_unusable');
        }

        if (! $provider->verifySignature($credentials, $envelope)) {
            /*
             * THE LINE. Nothing below this point has run: no parse, no customer
             * lookup, no conversation, no AI. The fingerprint is random because
             * an unverified body's contents are not evidence of anything and
             * must not be used as an idempotency key — doing so would let a
             * forger suppress a real notification by claiming its id first.
             */
            $this->record($account, 'unsigned:'.Str::uuid()->toString(), 'rejected', false, errorCode: 'signature_invalid');

            throw WebhookRejected::because('signature_invalid');
        }

        $batch = $provider->parse($envelope);

        $account->forceFill(['last_inbound_at' => CarbonImmutable::now()->utc()])->save();

        $handled = 0;

        foreach ($batch->messages as $message) {
            $handled += $this->message($account, $message) ? 1 : 0;
        }

        foreach ($batch->statuses as $status) {
            $handled += $this->status($account, $status) ? 1 : 0;
        }

        return $handled;
    }

    /**
     * One customer message: replay check, rate limit, then route.
     */
    private function message(WhatsAppAccount $account, InboundMessage $message): bool
    {
        $fingerprint = $this->fingerprint($account, 'message', $message->providerMessageId);

        if (! $this->claim($account, $fingerprint, 'message', $message->providerMessageId)) {
            // Meta delivers at least once. A repeat writes nothing, creates no
            // second conversation row and starts no second AI run (§10).
            return false;
        }

        /*
         * The channel is off: the center no longer owns it, or a manager
         * switched the account off. Recorded and dropped — before the rate
         * limit, the thread, the customer lookup and the assistant.
         */
        if (! $this->access->channelEnabled()) {
            $this->settle($account, $fingerprint, 'ignored', 'channel_inactive');

            return false;
        }

        if (! $account->enabled) {
            $this->settle($account, $fingerprint, 'ignored', 'account_disabled');

            return false;
        }

        /*
         * Two buckets, both keyed inside this tenant: the ACCOUNT, which stops
         * one misconfigured integration from starving the queue, and the
         * SENDER, which is the one that matters for abuse — a single number
         * flooding a center (§15).
         *
         * `allows` rather than `assertAllowed`: a flood is dropped QUIETLY and
         * recorded, because throwing would produce a non-2xx and Meta would
         * retry the same flood forever.
         */
        if (! $this->limiter->allows('whatsapp.account', $account->uuid)
            || ! $this->limiter->allows('whatsapp.sender', $message->fromPhone)) {
            $this->settle($account, $fingerprint, 'rate_limited');

            return false;
        }

        $this->limiter->record('whatsapp.account', $account->uuid);
        $this->limiter->record('whatsapp.sender', $message->fromPhone);

        $routed = $this->router->inbound($account, $message);

        $this->settle($account, $fingerprint, $routed ? 'accepted' : 'ignored');

        return $routed;
    }

    /**
     * A delivery status: find the outbound row it names, and record what the
     * provider said.
     *
     * This is how a `pending` or an `unknown` finally becomes a fact — the only
     * way it can, since Meta publishes no status-lookup endpoint (§11).
     */
    private function status(WhatsAppAccount $account, InboundStatus $status): bool
    {
        $fingerprint = $this->fingerprint(
            $account,
            'status',
            $status->providerMessageId.'|'.$status->state->value,
        );

        if (! $this->claim($account, $fingerprint, 'status', $status->providerMessageId)) {
            return false;
        }

        /** @var Message|null $message */
        $message = Message::query()
            ->where('provider_message_id', $status->providerMessageId)
            ->first();

        if (! $message instanceof Message) {
            /*
             * A status for a message this center does not have. Acknowledged
             * and changed nothing — the same answer a real one gets, so a
             * `wamid` cannot be probed for existence.
             */
            $this->settle($account, $fingerprint, 'ignored');

            return false;
        }

        $message->forceFill([
            'delivery_state' => $status->state,
            'delivered_at' => $status->at,
            'failure_code' => $status->errorCode ?? $message->failure_code,
        ])->save();

        $this->settle($account, $fingerprint, 'accepted');

        return true;
    }

    /**
     * Claims an item, or reports that it was already handled.
     *
     * `insertOrIgnore` against `unique(whatsapp_account_id, fingerprint)`. The
     * index IS the idempotency; nothing asks "have I seen this" (§10).
     */
    private function claim(WhatsAppAccount $account, string $fingerprint, string $kind, string $providerMessageId): bool
    {
        return $this->record($account, $fingerprint, 'processing', true, $kind, $providerMessageId);
    }

    private function settle(WhatsAppAccount $account, string $fingerprint, string $result, ?string $errorCode = null): void
    {
        $values = ['result' => $result, 'updated_at' => CarbonImmutable::now()->utc()];

        if ($errorCode !== null) {
            $values['error_code'] = $errorCode;
        }

        DB::connection('tenant')->table('whatsapp_webhook_events')
            ->where('whatsapp_account_id', $account->getKey())
            ->where('fingerprint', $fingerprint)
            ->update($values);
    }

    /**
     * Remembers that the provider completed the registration handshake.
     *
     * ONE row per account, refreshed on every successful handshake: the
     * fingerprint is fixed, so repeated registrations can never grow the
     * table. Written only after the verify token matched, so it is evidence
     * that whoever holds the center's Meta app pointed it here — which is what
     * the settings screen shows as "webhook verified". It carries no token,
     * no challenge and no query string.
     */
    private function recordHandshake(WhatsAppAccount $account): void
    {
        $fingerprint = $this->fingerprint($account, 'handshake', 'webhook');

        if ($this->record($account, $fingerprint, 'accepted', true, 'handshake')) {
            return;
        }

        $now = CarbonImmutable::now()->utc();

        DB::connection('tenant')->table('whatsapp_webhook_events')
            ->where('whatsapp_account_id', $account->getKey())
            ->where('fingerprint', $fingerprint)
            ->update(['received_at' => $now, 'updated_at' => $now]);
    }

    /**
     * @return bool whether THIS call created the row
     */
    private function record(
        WhatsAppAccount $account,
        string $fingerprint,
        string $result,
        bool $verified,
        string $kind = 'message',
        ?string $providerMessageId = null,
        ?string $errorCode = null,
    ): bool {
        $now = CarbonImmutable::now()->utc();

        $inserted = DB::connection('tenant')->table('whatsapp_webhook_events')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'whatsapp_account_id' => $account->getKey(),
            'fingerprint' => $fingerprint,
            'kind' => $kind,
            'provider_message_id' => $providerMessageId,
            'signature_verified' => $verified,
            'result' => $result,
            'error_code' => $errorCode,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted === 1;
    }

    /**
     * The provider's canonical identity for one item, hashed.
     *
     * Built from the ACCOUNT and the provider's own id, never from the body's
     * contents — so two different messages can never collide, and the same
     * message redelivered always produces the same value.
     */
    private function fingerprint(WhatsAppAccount $account, string $kind, string $identity): string
    {
        return hash('sha256', implode('|', [$account->provider, $account->uuid, $kind, $identity]));
    }
}
