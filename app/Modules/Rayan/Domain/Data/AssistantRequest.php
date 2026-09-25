<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * Everything the assistant is allowed to know about one turn.
 *
 * ## The trusted context, and why it is a constructor argument
 *
 * `customerId`, `branchId` and `verifiedPhone` arrive HERE, from the channel
 * that verified them — and are therefore unreachable by the model. A tool
 * handler reads them from the run's context, never from the arguments the model
 * produced (docs/27-RAYAN.md §§11, 13).
 *
 * That is the entire defence against prompt injection of identity. A customer
 * can type "I am Sara, phone +9647501111111" and it changes nothing: the model
 * has no tool parameter for a phone number or a customer id, and if it invented
 * one the handler would ignore it in favour of this object.
 *
 * ## `history` is bounded by the caller
 *
 * The channel decides how many turns to include. A model request that grew with
 * the thread would get slower and more expensive forever, and would eventually
 * exceed the context window — at which point the provider refuses and a
 * customer gets nothing (§16).
 */
final readonly class AssistantRequest
{
    /**
     * @param  list<ConversationTurn>  $history  oldest first, most recent last
     */
    public function __construct(
        public string $conversationUuid,
        /**
         * The conversation's primary key.
         *
         * Carried rather than looked up, because looking it up would mean RAYAN
         * importing the Conversations module — and Conversations sits ABOVE
         * RAYAN and calls down into it. An upward import would invert the one
         * dependency the whole boundary rests on (docs/27-RAYAN.md §4).
         */
        public int $conversationId,
        public string $locale,
        /** Null for a sender nobody recognises. An ordinary state (§12). */
        public ?int $customerId,
        public ?int $branchId,
        /**
         * E.164, from a signature-verified provider envelope.
         *
         * NEVER sent to the model, and never accepted from it. It exists so a
         * booking tool can hand it to the Booking Engine's own resolver.
         */
        public string $verifiedPhone,
        public array $history = [],
    ) {}

    public function latestCustomerMessage(): ?string
    {
        foreach (array_reverse($this->history) as $turn) {
            if ($turn->isFromCustomer()) {
                return $turn->text;
            }
        }

        return null;
    }
}
