<?php

declare(strict_types=1);

use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Rayan\Contracts\Assistant;
use App\Modules\Rayan\Domain\Data\AssistantReply;
use App\Modules\Rayan\Domain\Data\AssistantRequest;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Concurrency — a colleague stepping in while the model is thinking
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §§12, 34 · docs/27-RAYAN.md §16.
|
| `takeOver()` is allowed from `ai_active` ON PURPOSE: somebody reading the
| inbox who sees the bot getting it wrong should be able to step in without
| waiting for the customer to ask. That decision is what creates the window
| these tests are about.
|
| Routing checks the status, then calls the assistant — a request to a model
| provider that takes SECONDS. By the time it returns, the `Conversation` the
| router is holding is a stale copy of a row somebody else may have claimed.
| These tests commit that takeover from a GENUINELY SEPARATE CONNECTION while
| the call is in flight, which is what a second web request actually is.
|
| Two things must not happen afterwards:
|
|   1. the assistant's reply must not be sent underneath a colleague's
|   2. the thread must not be pushed back to `human_requested`, unassigning the
|      person already on it and paging the desk about a thread being handled
|
*/

/**
 * Registers an assistant that runs `$interrupt` mid-turn — exactly where a real
 * provider call would be waiting on the network — and then answers `$reply`.
 */
function assistantInterruptedBy(Closure $interrupt, AssistantReply $reply): void
{
    app()->instance(Assistant::class, new class($interrupt, $reply) implements Assistant
    {
        public function __construct(
            private readonly Closure $interrupt,
            private readonly AssistantReply $reply,
        ) {}

        public function answer(AssistantRequest $request): AssistantReply
        {
            ($this->interrupt)();

            return $this->reply;
        }
    });
}

it('does not answer a thread a colleague claimed while the model was thinking', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantAssistant();
        $account = $this->seedWhatsAppAccount();
        $owner = $this->ownerWithCatalogAccess();

        [$other, $release] = secondTenantConnection('takeover');

        /*
         * The takeover, committed on another connection while the turn is in
         * flight — the write a second web request makes when a staff member
         * presses "take over" (`ChangeConversationState::takeOver()`).
         */
        assistantInterruptedBy(function () use ($other, $owner): void {
            $other->table('conversations')->update([
                'status' => ConversationStatus::HumanActive->value,
                'assigned_user_id' => $owner->getKey(),
            ]);
        }, AssistantReply::say('We are open until 8pm.'));

        try {
            app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
                $this->metaTextNotification(text: 'What time do you close?')
            ));
        } finally {
            $release();
        }

        $conversation = $this->conversationFor();

        expect(Message::query()->where('author_type', 'ai')->count())
            ->toBe(0, 'the assistant replied underneath the colleague who took the thread');

        /*
         * And the takeover survived intact. A router writing the thread's
         * status from its stale copy would have undone a decision a person
         * made moments earlier.
         */
        expect($conversation?->status)->toBe(ConversationStatus::HumanActive)
            ->and($conversation?->assigned_user_id)->toBe((int) $owner->getKey())
            // The customer's message is still stored — abandoning the TURN must
            // never abandon the thing the turn was about.
            ->and(Message::query()->where('direction', 'inbound')->count())->toBe(1);
    });
});

it('answers normally when nobody stepped in', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantAssistant();
        $account = $this->seedWhatsAppAccount();
        $this->ownerWithCatalogAccess();

        // The SAME harness with the interruption doing nothing. Without this,
        // the test above would pass just as well against an assistant that
        // never answered anybody at all.
        assistantInterruptedBy(static function (): void {}, AssistantReply::say('We are open until 8pm.'));

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'What time do you close?')
        ));

        expect(Message::query()->where('author_type', 'ai')->count())->toBe(1)
            ->and(Message::query()->where('author_type', 'ai')->first()?->body)
            ->toBe('We are open until 8pm.')
            ->and($this->conversationFor()?->status)->toBe(ConversationStatus::AiActive);
    });
});

it('does not page the desk for a thread somebody is already handling', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantAssistant();
        $account = $this->seedWhatsAppAccount();
        $owner = $this->ownerWithCatalogAccess();

        [$other, $release] = secondTenantConnection('takeover_handoff');

        /*
         * The worse half of the race: the assistant gives up at the very moment
         * a colleague claims the thread. Handing off from the stale copy would
         * move an `human_active` thread back to `human_requested`, clear
         * `assigned_user_id`, and tell the desk a conversation needs somebody
         * — while somebody is already reading it.
         */
        assistantInterruptedBy(function () use ($other, $owner): void {
            $other->table('conversations')->update([
                'status' => ConversationStatus::HumanActive->value,
                'assigned_user_id' => $owner->getKey(),
            ]);
        }, AssistantReply::handOff('unsupported'));

        try {
            app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
                $this->metaTextNotification(text: 'I want to complain')
            ));
        } finally {
            $release();
        }

        $conversation = $this->conversationFor();

        expect($conversation?->status)->toBe(ConversationStatus::HumanActive)
            ->and($conversation?->assigned_user_id)->toBe((int) $owner->getKey());

        $paged = DB::connection('tenant')->table('notifications')
            ->where('type', NotificationType::ConversationTakeoverRequested->value)
            ->count();

        expect($paged)->toBe(0, 'the desk was paged about a thread already being handled')
            // No acknowledgement either. "A colleague will be with you" is
            // wrong when a colleague is already there.
            ->and(Message::query()->where('author_type', 'system')->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
