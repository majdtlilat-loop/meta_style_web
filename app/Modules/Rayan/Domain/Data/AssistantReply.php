<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * What the assistant produced, and what the channel should do about it.
 *
 * The assistant NEVER SENDS ANYTHING. It returns this, and the channel decides
 * — which is what keeps message persistence, delivery state, metering and the
 * outbound flood guard in one place instead of two
 * (docs/27-RAYAN.md §4).
 *
 * `handOff` is a request, not an action: RAYAN can say "a person is needed
 * here", and the Conversations module is what actually changes the status and
 * emits the fact that a staff notification listens for (§14).
 */
final readonly class AssistantReply
{
    private function __construct(
        /** What to send the customer, or null when there is nothing to say. */
        public ?string $text,
        public bool $handOff,
        /** A short code, never a sentence: `customer_asked`, `quota`, `failed`. */
        public ?string $handOffReason = null,
        /**
         * A branch a tool established during the turn, so the conversation can
         * carry it and staff can see which shop this is about.
         */
        public ?int $resolvedBranchId = null,
        /**
         * A customer a BOOKING tool resolved or created through the Booking
         * Engine's own resolver. The channel attaches it to the thread.
         *
         * The assistant cannot set this arbitrarily: it is only ever populated
         * from what the authoritative Booking action returned (§13).
         */
        public ?int $resolvedCustomerId = null,
    ) {}

    public static function say(
        string $text,
        ?int $resolvedBranchId = null,
        ?int $resolvedCustomerId = null,
    ): self {
        return new self($text, false, null, $resolvedBranchId, $resolvedCustomerId);
    }

    /**
     * A person is needed. `$text` is optional — a single fixed acknowledgement
     * where one helps, and nothing where a bot message would only get in the
     * way of the human who is about to arrive.
     */
    public static function handOff(string $reason, ?string $text = null): self
    {
        return new self($text, true, $reason);
    }

    /** Nothing to say and nothing to escalate. */
    public static function silent(): self
    {
        return new self(null, false);
    }
}
