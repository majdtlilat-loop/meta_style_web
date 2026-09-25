<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Localization\TenantLocales;
use App\Kernel\Security\AttemptLimiter;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Kernel\Usage\Usage;
use App\Modules\Conversations\Domain\Data\InboundMessage;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Enums\ConversationChannel;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Enums\MessageAuthor;
use App\Modules\Conversations\Domain\Enums\MessageDirection;
use App\Modules\Conversations\Domain\Enums\WhatsAppUsage;
use App\Modules\Conversations\Domain\Events\TakeoverRequested;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Rayan\Contracts\Assistant;
use App\Modules\Rayan\Domain\Data\AssistantReply;
use App\Modules\Rayan\Domain\Data\AssistantRequest;
use App\Modules\Rayan\Domain\Data\ConversationTurn;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What happens to a verified customer message once it is through the door.
 *
 *     find or open the thread
 *       → record what they said                      ← COMMITTED FIRST
 *       → resolve who they are, if we can
 *       → may the assistant answer?
 *           no  → hand off to a person
 *           yes → ask it, and send what it returns
 *
 * ## The inbound message is persisted before anything can go wrong
 *
 * This is the rule the whole failure story rests on. Whatever happens next —
 * the assistant is disabled, the quota is spent, the provider is down, the
 * model times out, a tool refuses — the customer's message is already in the
 * center's database and visible to staff. A design that only stored it after a
 * successful reply would lose exactly the messages that most needed a human
 * (docs/25-WHATSAPP.md §13, docs/27-RAYAN.md §16).
 *
 * ## The assistant cannot be reached at all unless everything is true
 *
 * The channel entitlement, the assistant entitlement, the conversation status,
 * the short-window rate limit and the commercial quota are all checked HERE,
 * before {@see Assistant::answer()} is called. None of them lives in a prompt,
 * and none is a decision the model participates in (§13).
 */
final class ConversationRouter
{
    public function __construct(
        private readonly CustomerResolution $customers,
        private readonly ConversationsAccess $access,
        private readonly OutboundMessages $outbound,
        private readonly Assistant $assistant,
        private readonly AttemptLimiter $limiter,
        private readonly Usage $usage,
        private readonly TenantLocales $locales,
        private readonly Dispatcher $events,
    ) {}

    /**
     * How many previous turns the assistant is shown.
     *
     * Bounded, because an unbounded history grows the request forever — slower
     * and more expensive every message, until it exceeds the context window and
     * the provider refuses outright, which would mean a customer gets nothing
     * precisely when the conversation has been going on longest (§16).
     */
    private const HISTORY_TURNS = 20;

    /**
     * @return bool whether the message was acted on
     */
    public function inbound(WhatsAppAccount $account, InboundMessage $inbound): bool
    {
        $conversation = $this->thread($account, $inbound);

        // Committed before anything else can fail.
        $this->persistInbound($conversation, $inbound);

        $this->meterInbound($inbound);

        /*
         * Resolution is attempted on EVERY message, not only the first.
         *
         * A sender who was a stranger last week may have become a customer at
         * the desk since. And re-resolving costs one indexed lookup, while
         * caching the answer would mean a thread that can never learn who it is
         * talking to (§7).
         */
        $this->identify($conversation, $inbound);

        if (! $conversation->allowsAi()) {
            /*
             * A person holds this thread, or it is closed. The message is
             * stored and the staff inbox shows it; the assistant stays silent,
             * because a bot reply arriving while a human is typing is the
             * clearest possible signal that nobody is really there (§12).
             */
            return true;
        }

        $refusal = $this->assistantRefusal($conversation);

        if ($refusal !== null) {
            $this->handOff($conversation, $refusal);

            return true;
        }

        $this->answer($conversation, $inbound);

        return true;
    }

    /**
     * The open thread for this number on this account, or a new one.
     *
     * A CLOSED conversation is never reopened. Somebody messaging again a month
     * later is a new conversation about a new thing, and stitching it onto a
     * finished thread would show a customer last month's context when they say
     * hello today.
     */
    private function thread(WhatsAppAccount $account, InboundMessage $inbound): Conversation
    {
        /** @var Conversation|null $existing */
        $existing = Conversation::query()
            ->where('whatsapp_account_id', $account->getKey())
            ->where('contact_phone', $inbound->fromPhone)
            ->open()
            ->orderByDesc('id')
            ->first();

        if ($existing instanceof Conversation) {
            return $existing;
        }

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->create([
            'channel' => ConversationChannel::WhatsApp,
            'whatsapp_account_id' => $account->getKey(),
            'contact_phone' => $inbound->fromPhone,
            'status' => ConversationStatus::AiActive,
            // The center's default language until the customer or a tool
            // establishes otherwise. Never a guess from the message text.
            'locale' => $this->locales->default(),
        ]);

        return $conversation;
    }

    private function persistInbound(Conversation $conversation, InboundMessage $inbound): void
    {
        DB::connection('tenant')->transaction(function () use ($conversation, $inbound): void {
            $conversation->messages()->create([
                'direction' => MessageDirection::Inbound,
                'author_type' => MessageAuthor::Customer,
                'body' => $inbound->text,
                'provider_message_id' => $inbound->providerMessageId,
                // No delivery state: this one ARRIVED. There is no outcome of
                // ours to report (§9).
                'sent_at' => $inbound->sentAt,
            ]);

            $conversation->forceFill(['last_message_at' => $inbound->sentAt])->save();
        });
    }

    /**
     * Attaches the customer this verified number belongs to, if any.
     *
     * Never creates one, and never overwrites one that is already attached —
     * a thread that has been resolved to a person stays resolved, because the
     * alternative is a conversation whose subject changes underneath a staff
     * member who is reading it.
     */
    private function identify(Conversation $conversation, InboundMessage $inbound): void
    {
        if ($conversation->isIdentified()) {
            return;
        }

        $customer = $this->customers->resolve($inbound->fromPhone);

        if (! $customer instanceof Customer) {
            return;
        }

        $conversation->forceFill([
            'customer_id' => $customer->getKey(),
            // Their own language, now that we know whose it is (§17).
            'locale' => $this->locales->resolve($customer->preferred_locale),
        ])->save();
    }

    /**
     * Why the assistant may NOT answer, or null when it may.
     *
     * Every check is here rather than inside the assistant, so that "the model
     * decided to ignore its instructions" can never be the reason a quota was
     * bypassed (§13).
     */
    private function assistantRefusal(Conversation $conversation): ?string
    {
        if (! $this->access->assistantEnabled()) {
            // The center owns WhatsApp but not RAYAN — a real configuration:
            // messages arrive and staff answer them by hand.
            return 'assistant_disabled';
        }

        /*
         * The short-window guard. Separate from the commercial quota and for a
         * different purpose: "the plan allows 50,000 runs a month" has never
         * meant "fifty of them in the next four seconds" (§15).
         *
         * `allows` rather than a throw: this is a routing decision, and the
         * answer is a hand-off rather than an error.
         */
        if (! $this->limiter->allows('rayan.conversation', $conversation->uuid)) {
            return 'rate_limited';
        }

        $this->limiter->record('rayan.conversation', $conversation->uuid);

        /*
         * The commercial allowance, checked but NOT spent here. Spending
         * happens atomically inside the run, so two simultaneous messages
         * cannot both take the last unit (§46).
         */
        if (! $this->usage->allows('ai_runs')) {
            return 'quota';
        }

        return null;
    }

    /**
     * Asks the assistant, and sends whatever it returns.
     */
    private function answer(Conversation $conversation, InboundMessage $inbound): void
    {
        try {
            $reply = $this->assistant->answer(new AssistantRequest(
                conversationUuid: $conversation->uuid,
                conversationId: (int) $conversation->getKey(),
                locale: $conversation->locale,
                customerId: $conversation->customer_id,
                branchId: $conversation->branch_id,
                // From the VERIFIED envelope. Never from the message text, and
                // never a value the model produced (§6).
                verifiedPhone: $inbound->fromPhone,
                history: $this->history($conversation),
            ));
        } catch (Throwable $e) {
            /*
             * The assistant is contracted not to throw for an ordinary failure,
             * so reaching here means something genuinely unexpected. The
             * customer's message is already stored; a person takes it from
             * here, and the exception is reported rather than swallowed.
             */
            report($e);

            if (! $this->humanTookOver($conversation)) {
                $this->handOff($conversation, 'failed');
            }

            return;
        }

        /*
         * RE-READ, because the status checked before this turn began is now
         * seconds old.
         *
         * `Assistant::answer()` is a call to a model provider, and a whole turn
         * can take several seconds; `takeOver()` is deliberately allowed from
         * `ai_active` so that a staff member watching the bot get it wrong can
         * step in without waiting for the customer to ask. That is exactly this
         * window. Carrying on would send a bot reply underneath a colleague's
         * — the one thing a takeover exists to prevent — and `handOff()` would
         * push an `human_active` thread back to `human_requested`, unassigning
         * the person already on it and paging the desk for a thread somebody is
         * already handling (§12, §34).
         *
         * The turn is simply abandoned. The customer's message is stored, a
         * person holds the thread, and the run has already been metered by the
         * assistant — nothing is lost but a reply nobody should send.
         */
        if ($this->humanTookOver($conversation)) {
            return;
        }

        $this->applyResolutions($conversation, $reply);

        if ($reply->text !== null && $reply->text !== '') {
            $this->trySend($conversation, $reply->text, MessageAuthor::Ai);
        }

        if ($reply->handOff) {
            $this->handOff($conversation, (string) $reply->handOffReason, alreadyAcknowledged: $reply->text !== null);

            return;
        }

        // A clean turn clears the failure streak, so a thread that recovers is
        // not handed off because of something that went wrong an hour ago.
        if ($conversation->consecutive_failures > 0) {
            $conversation->forceFill(['consecutive_failures' => 0])->save();
        }
    }

    /**
     * Carries forward what the turn established.
     *
     * Only ever from what an authoritative Action returned — a branch a lookup
     * confirmed, a customer the Booking Engine's own resolver produced. The
     * model cannot populate these (§13).
     */
    private function applyResolutions(Conversation $conversation, AssistantReply $reply): void
    {
        $changes = [];

        if ($reply->resolvedBranchId !== null && $conversation->branch_id === null) {
            $changes['branch_id'] = $reply->resolvedBranchId;
        }

        if ($reply->resolvedCustomerId !== null && $conversation->customer_id === null) {
            $changes['customer_id'] = $reply->resolvedCustomerId;
        }

        if ($changes !== []) {
            $conversation->forceFill($changes)->save();
        }
    }

    /**
     * Moves the thread to `human_requested` and says so, exactly once.
     *
     * The status change is COMMITTED before the event is dispatched, so a
     * notification failure can never leave a conversation that nobody is
     * answering and nobody has been told about (ADR-067).
     */
    private function handOff(Conversation $conversation, string $reason, bool $alreadyAcknowledged = false): void
    {
        if ($conversation->status === ConversationStatus::HumanRequested) {
            // Already waiting for somebody. Asking twice would notify twice.
            return;
        }

        $conversation->forceFill([
            'status' => ConversationStatus::HumanRequested,
            'consecutive_failures' => $conversation->consecutive_failures + 1,
        ])->save();

        if (! $alreadyAcknowledged) {
            /*
             * One fixed, translated sentence — never an explanation.
             *
             * In particular it must never say that the center has run out of a
             * paid allowance. A person messaging a salon about their haircut is
             * not a party to the salon's billing, and telling them would
             * embarrass the center to their own customer (§51).
             */
            $this->trySend(
                $conversation,
                __('conversations.handoff_acknowledgement', [], $conversation->locale),
                MessageAuthor::System,
            );
        }

        $this->events->dispatch(new TakeoverRequested(
            conversationUuid: $conversation->uuid,
            branchId: $conversation->branch_id,
            reason: $reason,
            customerName: $conversation->customer?->name,
        ));
    }

    /**
     * Has a person claimed this thread since the turn began?
     *
     * Reads the COLUMN rather than refreshing the model: the in-memory instance
     * carries changes this turn has made that are not yet written, and
     * refreshing it would discard them.
     */
    private function humanTookOver(Conversation $conversation): bool
    {
        return Conversation::query()
            ->where('id', $conversation->getKey())
            ->value('status') === ConversationStatus::HumanActive;
    }

    /**
     * Sends, and never lets a send failure break the routing.
     *
     * The outbound row records what happened; the inbound message is already
     * stored. A provider outage must not turn into an exception that Meta sees
     * as a non-2xx and retries forever (§13).
     */
    private function trySend(Conversation $conversation, string $text, MessageAuthor $author): void
    {
        try {
            $this->outbound->send($conversation, OutboundMessage::text($conversation->contact_phone, $text), $author);
        } catch (ConversationFailed|TooManyAttempts $e) {
            report($e);
        }
    }

    /**
     * The last few turns, oldest first.
     *
     * @return list<ConversationTurn>
     */
    private function history(Conversation $conversation): array
    {
        /** @var list<Message> $messages */
        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->limit(self::HISTORY_TURNS)
            ->get()
            ->reverse()
            ->values()
            ->all();

        $turns = [];

        foreach ($messages as $message) {
            /*
             * SYSTEM messages are excluded. "A colleague will be with you
             * shortly" is the application talking about itself, and feeding it
             * back would have the model treat its own plumbing as part of the
             * conversation (§9).
             */
            $turns[] = $message->isInbound()
                ? ConversationTurn::fromCustomer($message->body)
                : ($message->author_type === MessageAuthor::System
                    ? null
                    : ConversationTurn::fromAssistant($message->body));
        }

        return array_values(array_filter($turns));
    }

    private function meterInbound(InboundMessage $inbound): void
    {
        try {
            // Keyed on the PROVIDER's message id, so a redelivery Meta sent
            // twice is counted once even if it reached here twice (§5).
            $this->usage->meter(WhatsAppUsage::Inbound->code(), 'wa_inbound', $inbound->providerMessageId);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
