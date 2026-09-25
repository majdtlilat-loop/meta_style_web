<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Security\AttemptLimiter;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Kernel\Usage\Usage;
use App\Modules\Conversations\Domain\Data\MessagingCapabilities;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Data\SendResult;
use App\Modules\Conversations\Domain\Enums\DeliveryState;
use App\Modules\Conversations\Domain\Enums\MessageAuthor;
use App\Modules\Conversations\Domain\Enums\MessageDirection;
use App\Modules\Conversations\Domain\Enums\WhatsAppUsage;
use App\Modules\Conversations\Domain\Events\ProviderSendFailed;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Sending a message: persist first, send second, record the outcome third.
 *
 * ## The order is the whole design (docs/25-WHATSAPP.md §10)
 *
 *     1. write the message row as `pending`, and COMMIT it
 *     2. call the provider — outside any transaction
 *     3. update the row with what was observed
 *
 * Sending inside a transaction would be the obvious mistake, and it has two
 * failure modes that are both worse than this:
 *
 *   - a rollback AFTER the provider accepted leaves a message the customer has
 *     received and the center has no record of — the thread is then a lie;
 *   - a slow provider holds a database transaction open for its timeout.
 *
 * Persisting first means the worst case is a row that says `pending` or
 * `unknown` forever, which is visible, honest, and recoverable by a person.
 *
 * ## What is never done automatically
 *
 * Retrying an `unknown`. The provider may have delivered it, and Meta's send
 * endpoint takes no idempotency key — verified, and reported by the adapter's
 * {@see MessagingCapabilities::$idempotentSend}
 * — so a retry would be a second copy at the customer's phone. It stays
 * `unknown` and a human decides (§9).
 */
final class OutboundMessages
{
    public function __construct(
        private readonly WhatsAppConnections $connections,
        private readonly AttemptLimiter $limiter,
        private readonly Usage $usage,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Persists and sends one message in a conversation.
     *
     * @throws ConversationFailed when the account is unusable
     * @throws TooManyAttempts when the outbound flood guard trips
     */
    public function send(
        Conversation $conversation,
        OutboundMessage $outbound,
        MessageAuthor $author,
        ?int $authorUserId = null,
    ): Message {
        $account = $conversation->account;

        if (! $account instanceof WhatsAppAccount || ! $account->isUsable()) {
            throw ConversationFailed::notConfigured();
        }

        /*
         * Meta Style's OWN flood guard, not Meta's. A loop that answered itself
         * would message a customer dozens of times before anybody noticed, and
         * a provider's own limits are neither published as a number this code
         * could rely on nor ours to depend on (§15).
         */
        $this->limiter->assertAllowed('whatsapp.outbound', $conversation->uuid);
        $this->limiter->record('whatsapp.outbound', $conversation->uuid);

        $message = $this->persist($conversation, $outbound, $author, $authorUserId);

        $result = $this->deliver($account, $outbound);

        $this->settle($message, $result);
        $this->meter($message, $result, $outbound);

        if ($result->state === DeliveryState::Failed) {
            /*
             * Only a REFUSAL raises this. An `unknown` is visible in the thread
             * but does not page anybody — alerting on every transient blip is
             * how staff learn to ignore the alert that matters (§13).
             */
            $this->events->dispatch(new ProviderSendFailed(
                conversationUuid: $conversation->uuid,
                branchId: $conversation->branch_id,
                failureCode: (string) $result->failureCode,
            ));
        }

        return $message;
    }

    /**
     * The row, committed before anything leaves the building.
     */
    private function persist(
        Conversation $conversation,
        OutboundMessage $outbound,
        MessageAuthor $author,
        ?int $authorUserId,
    ): Message {
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'direction' => MessageDirection::Outbound,
            'author_type' => $author,
            'author_user_id' => $authorUserId,
            'body' => $outbound->body,
            'delivery_state' => DeliveryState::Pending,
            'template_name' => $outbound->templateName,
        ]);

        $conversation->forceFill(['last_message_at' => CarbonImmutable::now()->utc()])->save();

        return $message;
    }

    /**
     * Calls the provider, and NEVER lets a throwable become a false outcome.
     *
     * An adapter that throws has told us nothing about whether the message
     * arrived, which is exactly {@see SendResult::unknown()}. Converting it to
     * `failed` here would re-open the duplicate-message hole the adapter was
     * careful to close.
     */
    private function deliver(WhatsAppAccount $account, OutboundMessage $outbound): SendResult
    {
        try {
            return $this->connections->provider($account)->send(
                $this->connections->credentials($account),
                $outbound,
            );
        } catch (ConversationFailed) {
            // Unusable credentials. Nothing was attempted, so there is no
            // ambiguity to preserve.
            return SendResult::failed('not_configured');
        } catch (Throwable $e) {
            report($e);

            return SendResult::unknown('adapter');
        }
    }

    private function settle(Message $message, SendResult $result): void
    {
        $now = CarbonImmutable::now()->utc();

        $message->forceFill([
            'delivery_state' => $result->state,
            'provider_message_id' => $result->providerMessageId,
            'failure_code' => $result->failureCode,
            // `sent_at` means "we handed it over and were told yes". An
            // `unknown` deliberately gets none: stamping one would record a
            // moment of delivery nobody observed (§9).
            'sent_at' => $result->state === DeliveryState::Sent ? $now : null,
        ])->save();
    }

    /**
     * Counts it, exactly once, keyed on the message's own uuid.
     *
     * Metering must never be able to break a conversation: a usage table that
     * is briefly unavailable is an analytics problem, and the message has
     * already been sent to a real person. So a failure here is reported and
     * swallowed (§13, ADR-067).
     */
    private function meter(Message $message, SendResult $result, OutboundMessage $outbound): void
    {
        $resource = match (true) {
            $result->state === DeliveryState::Failed => WhatsAppUsage::Failed,
            $outbound->isTemplate() => WhatsAppUsage::Template,
            default => WhatsAppUsage::Outbound,
        };

        try {
            // `meter`, never `consume`: WhatsApp volume is recorded and
            // reported, and refuses nothing. Only `ai_runs` is enforced (§3).
            $this->usage->meter($resource->code(), 'wa_message', $message->uuid);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
