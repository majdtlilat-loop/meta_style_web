<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Application\ConversationsAccess;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\Conversation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Taking a conversation over, handing it back, and closing it.
 *
 * ## Why all three are one Action
 *
 * Because they are the same operation — a legal move on one small state
 * machine — and splitting them would mean three places that each have to
 * remember the lock, the authorization, the audit entry and which moves are
 * allowed (docs/25-WHATSAPP.md §12).
 *
 *     ai_active ──► human_requested ──► human_active ──► closed
 *         ▲                                  │
 *         └──────────────────────────────────┘
 *
 * ## Every move locks the row first
 *
 * Two staff members pressing "take over" at the same instant must not both end
 * up believing they hold the thread — one wins, and the other is told who has
 * it. The check happens against the LOCKED row, never the one that was read to
 * render the screen, which may be seconds old (the rule every queue and sale
 * mutation already follows).
 *
 * ## Closing survives a downgrade
 *
 * `authorize()`, not `ensure()`. A center that has lost the WhatsApp
 * entitlement must still be able to tidy up the threads it already has —
 * refusing would leave conversations open forever with no way to finish them,
 * which is worse than never having had the feature (§19, the Booking rule).
 */
final class ChangeConversationState
{
    public function __construct(
        private readonly ConversationsAccess $access,
        private readonly Audit $audit,
    ) {}

    /**
     * A member of staff takes the thread. The assistant goes silent.
     *
     * Allowed from `ai_active` as well as `human_requested`: somebody reading
     * the inbox who sees the bot getting it wrong should be able to step in
     * without waiting for the customer to ask.
     *
     * @throws AuthorizationException
     * @throws ConversationFailed
     */
    public function takeOver(Conversation $conversation, User $user): Conversation
    {
        $this->access->authorize(
            $user,
            Permission::ConversationTakeover,
            $conversation,
            'You may not take over conversations.',
        );

        return $this->move(
            $conversation,
            ConversationStatus::HumanActive,
            [ConversationStatus::AiActive, ConversationStatus::HumanRequested],
            $user,
            'conversation.taken_over',
            assignTo: (int) $user->getKey(),
        );
    }

    /**
     * Hands the thread back to the assistant.
     *
     * Needs the channel entitlement — `ensure()`, not `authorize()` — because
     * this is NEW automated activity rather than tidying up: a center that has
     * lost the feature must not be able to switch the bot back on (§19).
     *
     * The failure counter is cleared, so a thread that was handed off after
     * repeated errors gets a clean start rather than escalating again on the
     * next message.
     *
     * @throws AuthorizationException
     * @throws ConversationFailed
     */
    public function returnToAssistant(Conversation $conversation, User $user): Conversation
    {
        $this->access->ensure(
            $user,
            Permission::ConversationTakeover,
            $conversation,
            'You may not take over conversations.',
        );

        if (! $this->access->assistantEnabled()) {
            throw ConversationFailed::policy('The assistant is not available for this center.');
        }

        return $this->move(
            $conversation,
            ConversationStatus::AiActive,
            [ConversationStatus::HumanActive, ConversationStatus::HumanRequested],
            $user,
            'conversation.returned_to_assistant',
            clearFailures: true,
        );
    }

    /**
     * Finishes the conversation. A later message opens a new one.
     *
     * @throws AuthorizationException
     * @throws ConversationFailed
     */
    public function close(Conversation $conversation, User $user): Conversation
    {
        $this->access->authorize(
            $user,
            Permission::ConversationTakeover,
            $conversation,
            'You may not close conversations.',
        );

        return $this->move(
            $conversation,
            ConversationStatus::Closed,
            [ConversationStatus::AiActive, ConversationStatus::HumanRequested, ConversationStatus::HumanActive],
            $user,
            'conversation.closed',
        );
    }

    /**
     * The customer asked for a person — from the channel, with no user.
     *
     * Kept here rather than in the router so that every status change goes
     * through one place. No audit entry: a customer asking for help is not a
     * privileged action, and auditing every one of them would bury the moves
     * that ARE privileged (docs/08-AUDIT-SECURITY.md §3).
     */
    public function requestHuman(Conversation $conversation): Conversation
    {
        return DB::connection('tenant')->transaction(function () use ($conversation): Conversation {
            $locked = $this->lock($conversation);

            if (! $locked->status->allowsAi()) {
                // Already with a person, or closed. Nothing to do.
                return $locked;
            }

            $locked->forceFill(['status' => ConversationStatus::HumanRequested])->save();

            return $locked;
        });
    }

    /**
     * @param  list<ConversationStatus>  $from
     *
     * @throws ConversationFailed
     */
    private function move(
        Conversation $conversation,
        ConversationStatus $to,
        array $from,
        User $user,
        string $action,
        ?int $assignTo = null,
        bool $clearFailures = false,
    ): Conversation {
        $moved = DB::connection('tenant')->transaction(
            function () use ($conversation, $to, $from, $assignTo, $clearFailures): Conversation {
                $locked = $this->lock($conversation);

                if ($locked->status === $to) {
                    // Idempotent: pressing "close" twice is not an error, and
                    // telling somebody their own action failed would be wrong.
                    return $locked;
                }

                if (! in_array($locked->status, $from, true)) {
                    throw ConversationFailed::policy('That conversation cannot be changed from its current state.');
                }

                $changes = ['status' => $to];

                /*
                 * The assignee is set when a person takes it and CLEARED when
                 * they do not hold it any more. Leaving a stale assignee on a
                 * thread the assistant is answering would show a colleague's
                 * name against messages they did not write.
                 */
                $changes['assigned_user_id'] = $to === ConversationStatus::HumanActive ? $assignTo : null;

                if ($clearFailures) {
                    $changes['consecutive_failures'] = 0;
                }

                $locked->forceFill($changes)->save();

                return $locked;
            }
        );

        $this->record($moved, $user, $action);

        return $moved;
    }

    private function lock(Conversation $conversation): Conversation
    {
        /** @var Conversation|null $locked */
        $locked = Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

        if (! $locked instanceof Conversation) {
            throw ConversationFailed::policy('That conversation is no longer available.');
        }

        return $locked;
    }

    /**
     * NO CUSTOMER PHONE, and no message text.
     *
     * The target is the conversation's uuid; who it is with is a lookup away
     * for anybody investigating, and putting a number in an audit row is the
     * one thing ADR-042 exists to stop.
     */
    private function record(Conversation $conversation, User $user, string $action): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Config,
            actor: new Actor(ActorType::Staff, AuditSource::Web, $user->uuid, $user->name),
            targetType: Conversation::class,
            targetId: $conversation->uuid,
            meta: [
                'status' => $conversation->status->value,
                'channel' => $conversation->channel->value,
            ],
        ));
    }
}
