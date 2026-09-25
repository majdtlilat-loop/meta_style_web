<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Events;

/**
 * A conversation needs a human, and none is on it.
 *
 * ## Why an event rather than a notification call
 *
 * Because Conversations must not import Notifications. Modules emit FACTS;
 * Notifications listens and decides who is told (ADR-067). The direction is
 * enforced by architecture tests, and it is what keeps a notification failure
 * from being able to corrupt the conversation that caused it — the row saying
 * `human_requested` is already committed before this is dispatched
 * (docs/25-WHATSAPP.md §13).
 *
 * Identifiers and plain values only, never models: a listener that received an
 * Eloquent object would be free to write through it.
 */
final readonly class TakeoverRequested
{
    public function __construct(
        public string $conversationUuid,
        public ?int $branchId,
        /**
         * Why a person is needed. One of a small set of codes — the customer
         * asked, the AI quota is spent, the model kept failing, the request is
         * unsupported — so a listener can phrase the alert without parsing a
         * sentence (§12).
         */
        public string $reason,
        public ?string $customerName = null,
    ) {}
}
