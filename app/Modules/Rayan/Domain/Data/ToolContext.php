<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * The TRUSTED half of a tool call.
 *
 * Every tool handler receives two things: this, and the arguments the model
 * produced. The split is the security model (docs/27-RAYAN.md §13):
 *
 *   HERE              the tenant (implicitly, via the bound connection), the
 *                     conversation, the resolved customer, the verified phone,
 *                     the branch. Established by the channel from a
 *                     signature-verified envelope and a database lookup.
 *   the ARGUMENTS     a service, a date, a time, a booking reference. Business
 *                     input, validated against a schema, and worthless on its
 *                     own.
 *
 * NOTHING IN HERE IS EXPRESSIBLE AS A TOOL PARAMETER. There is no
 * `customer_id`, no `phone`, no `tenant`, no `user_id` in any tool schema — so
 * a model instructed by a customer to "book this for Sara on +9647501111111"
 * has no way to say it, and if it invented a field the handler would read this
 * object anyway (§11).
 */
final readonly class ToolContext
{
    public function __construct(
        public string $conversationUuid,
        public string $locale,
        /** Null when the sender is not a known customer. */
        public ?int $customerId,
        public ?int $branchId,
        /**
         * E.164, from a signature-verified provider envelope.
         *
         * Never sent to the model. Handed to the Booking Engine's resolver when
         * a first-time customer books, which is the only way a customer record
         * is ever created from a conversation (§12).
         */
        public string $verifiedPhone,
    ) {}

    public function isIdentified(): bool
    {
        return $this->customerId !== null;
    }

    /**
     * A copy carrying a customer the booking flow just resolved.
     *
     * So a `create_booking` for a first-time sender makes the SAME turn's later
     * tool calls aware of who they now are, without the model being consulted
     * about it.
     */
    public function withCustomer(int $customerId): self
    {
        return new self($this->conversationUuid, $this->locale, $customerId, $this->branchId, $this->verifiedPhone);
    }

    public function withBranch(int $branchId): self
    {
        return new self($this->conversationUuid, $this->locale, $this->customerId, $branchId, $this->verifiedPhone);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        // The verified phone is a customer's number.
        return [
            'conversationUuid' => $this->conversationUuid,
            'locale' => $this->locale,
            'customerId' => $this->customerId,
            'branchId' => $this->branchId,
            'verifiedPhone' => '[redacted]',
        ];
    }
}
