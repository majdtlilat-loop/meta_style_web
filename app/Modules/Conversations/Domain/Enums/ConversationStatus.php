<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

/**
 * Who is answering this conversation.
 *
 * FOUR STATES, and the whole hand-off model is in them
 * (docs/25-WHATSAPP.md §12):
 *
 *     ai_active ──► human_requested ──► human_active ──► closed
 *         ▲                                  │
 *         └──────────────────────────────────┘
 *
 * `human_requested` is a real state rather than a flag on `human_active`
 * because it is the one a staff notification is about: somebody has asked for a
 * person and nobody has picked it up yet. Collapsing it into "human" would make
 * "waiting for us" indistinguishable from "being handled", which is the only
 * distinction a busy front desk actually needs.
 */
enum ConversationStatus: string
{
    /** The AI answers. The normal state. */
    case AiActive = 'ai_active';

    /**
     * A person is needed and none has arrived.
     *
     * The AI does NOT answer here. That is deliberate: a customer who has just
     * asked for a human and gets another bot reply has been told their request
     * was ignored (§12).
     */
    case HumanRequested = 'human_requested';

    /** A member of staff is holding the thread. The AI stays silent. */
    case HumanActive = 'human_active';

    case Closed = 'closed';

    /**
     * May the AI generate and send a reply right now?
     *
     * The single question every inbound message asks, answered in one place so
     * a new state cannot be added without deciding it.
     */
    public function allowsAi(): bool
    {
        return $this === self::AiActive;
    }

    /**
     * Is this thread still live, for the inbox and for inbound routing?
     */
    public function isOpen(): bool
    {
        return $this !== self::Closed;
    }

    public function needsAttention(): bool
    {
        return $this === self::HumanRequested;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $status): string => $status->value,
            array_filter(self::cases(), static fn (self $status): bool => $status->isOpen()),
        ));
    }
}
