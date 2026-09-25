<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Customers\Application\CustomerPresenter;

/**
 * The one shape a conversation is presented in, to any staff surface.
 *
 * ## The customer's phone number goes through `CustomerPresenter`
 *
 * A conversation IS a phone number — it is the routing key and it sits in a
 * column right there. Rendering it raw in the inbox would hand every staff
 * member with `conversation.view` a contact list, quietly bypassing
 * `customer.contact.view` and the masking that Phase 5 built (ADR-042).
 *
 * So a thread that resolved to a customer is presented through that customer,
 * with the same masking every other screen applies. A thread that has NOT
 * resolved to anybody has no customer to delegate to, and its number is masked
 * here to the same shape — because an unknown number is exactly as personal as
 * a known one.
 *
 * ## Never in any shape below
 *
 * Provider credentials, the app secret, the verify token, the raw webhook body,
 * a booking verification code, or a digest of one (§20).
 */
final class ConversationPresenter
{
    public function __construct(private readonly CustomerPresenter $customers) {}

    /**
     * The inbox row: enough to triage, not enough to be a contact list.
     *
     * @return array<string, mixed>
     */
    public function summary(Conversation $conversation, ?User $viewer): array
    {
        return [
            'uuid' => $conversation->uuid,
            'channel' => $conversation->channel->value,
            'status' => $conversation->status->value,
            'locale' => $conversation->locale,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'needs_attention' => $conversation->status->needsAttention(),

            'customer' => $conversation->relationLoaded('customer') && $conversation->customer !== null
                ? $this->customers->summary($conversation->customer, $viewer)
                // Not resolved to anybody. Masked to the same shape a known
                // customer's number gets, rather than shown in full.
                : ['name' => null, 'phone' => $this->maskedPhone($conversation)],

            'branch' => $conversation->relationLoaded('branch') && $conversation->branch !== null
                ? ['uuid' => $conversation->branch->uuid, 'name' => $conversation->branch->name->get()]
                : null,

            'assigned_to' => $conversation->relationLoaded('assignee') && $conversation->assignee !== null
                ? $conversation->assignee->name
                : null,
        ];
    }

    /**
     * The thread view: the summary plus the connection it runs over.
     *
     * @param  list<Message>  $messages
     * @return array<string, mixed>
     */
    public function detail(Conversation $conversation, array $messages, ?User $viewer): array
    {
        $account = $conversation->relationLoaded('account') ? $conversation->account : null;

        return array_merge($this->summary($conversation, $viewer), [
            'messages' => $this->messages($messages),
            'account' => $account instanceof WhatsAppAccount
                // The LABEL only. Never the phone number id, never a credential
                // and never the webhook URL — none of which a thread view needs.
                ? ['display_name' => $account->display_name]
                : null,
        ]);
    }

    /**
     * @param  list<Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function messages(array $messages): array
    {
        return array_map(static fn (Message $message): array => [
            'uuid' => $message->uuid,
            'direction' => $message->direction->value,
            'author' => $message->author_type->value,
            // The staff member's NAME, so a manager reading a thread can see
            // which colleague wrote what. Not their uuid, not their email.
            'author_name' => $message->relationLoaded('author') ? $message->author?->name : null,
            'body' => $message->body,
            'template' => $message->template_name,
            'delivery_state' => $message->delivery_state?->value,
            'needs_attention' => $message->needsAttention(),
            'sent_at' => $message->sent_at?->toIso8601String(),
            'created_at' => $message->created_at?->toIso8601String(),
            /*
             * `provider_message_id` is deliberately absent. It identifies the
             * message inside Meta's systems and is useful only for support,
             * which reads the database rather than the inbox.
             */
        ], $messages);
    }

    /**
     * The last two digits, in the shape `CustomerPresenter` uses.
     *
     * Enough for a staff member to match a thread against a customer who is
     * standing in front of them; not enough to be a number anybody can dial.
     */
    private function maskedPhone(Conversation $conversation): string
    {
        return '••••'.mb_substr($conversation->contact_phone, -2);
    }
}
