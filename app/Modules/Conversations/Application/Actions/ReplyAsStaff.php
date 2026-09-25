<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Modules\Conversations\Application\ConversationsAccess;
use App\Modules\Conversations\Application\OutboundMessages;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Enums\MessageAuthor;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * A member of staff writing to a customer, from the center's own number.
 *
 * ## Replying implies taking over
 *
 * A person typing into a thread the assistant is still answering would produce
 * two voices talking over each other, and the customer has no way to tell which
 * is which. So a reply on an `ai_active` thread MOVES it to `human_active`
 * first — through the same Action that a deliberate takeover uses, so the lock,
 * the authorization and the audit entry are the same ones
 * (docs/25-WHATSAPP.md §12).
 *
 * That also means `conversation.reply` alone is not enough to silence the
 * assistant: the takeover permission is checked too, by the Action that does
 * the moving. Somebody who may reply but may not take over can still write in a
 * thread a colleague is already holding.
 *
 * ## What it refuses
 *
 * A CLOSED conversation. Reopening one by replying would resurrect a thread the
 * center deliberately finished, and the customer would receive a message in
 * what is, to them, an old conversation.
 */
final class ReplyAsStaff
{
    /**
     * Long enough for a real answer, short enough that nobody pastes an essay
     * into WhatsApp. Also a guard on what one request can hand a provider.
     */
    public const MAX_LENGTH = 2000;

    public function __construct(
        private readonly ConversationsAccess $access,
        private readonly OutboundMessages $outbound,
        private readonly ChangeConversationState $state,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ConversationFailed
     * @throws TooManyAttempts
     */
    public function __invoke(Conversation $conversation, User $user, string $body): Message
    {
        /*
         * `ensure()`: sending a message to a customer is NEW activity on the
         * channel, so it needs the entitlement. Reading the thread does not
         * (§19).
         */
        $this->access->ensure(
            $user,
            Permission::ConversationReply,
            $conversation,
            'You may not reply to conversations.',
        );

        $text = trim($body);

        if ($text === '') {
            throw ConversationFailed::policy('A reply cannot be empty.');
        }

        if (mb_strlen($text) > self::MAX_LENGTH) {
            throw ConversationFailed::policy('That reply is too long.');
        }

        if (! $conversation->isOpen()) {
            throw ConversationFailed::policy('That conversation is closed.');
        }

        if ($conversation->allowsAi()) {
            // Two voices in one thread is worse than a slow reply.
            $conversation = $this->state->takeOver($conversation, $user);
        }

        return $this->outbound->send(
            $conversation,
            OutboundMessage::text($conversation->contact_phone, $text),
            MessageAuthor::Staff,
            (int) $user->getKey(),
        );
    }
}
