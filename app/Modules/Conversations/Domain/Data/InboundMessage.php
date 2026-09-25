<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

use Carbon\CarbonImmutable;

/**
 * One customer message, read out of a VERIFIED provider notification.
 *
 * ## `fromPhone` is the only identity that counts
 *
 * It comes from `entry[].changes[].value.messages[].from` in a notification
 * whose signature verified against the center's app secret. That is what makes
 * it evidence of control of the number (ADR-070).
 *
 * Nothing else may ever establish who is speaking — not text in the message
 * body, not an AI tool argument, not a query parameter, not a field somebody
 * added to the JSON. Those are all attacker-supplied, and a system that accepts
 * a phone number from any of them has no identity model at all
 * (docs/25-WHATSAPP.md §6).
 *
 * ## What is deliberately absent
 *
 * The provider's `contacts[].profile.name`. Meta supplies it, and it is whatever
 * the sender typed into their own phone — not a fact about a customer, and not
 * something to file in a CRM. The name a booking is made under is asked for and
 * confirmed in the conversation instead (§7).
 */
final readonly class InboundMessage
{
    public function __construct(
        /** `wamid.…` — the replay key (§10). */
        public string $providerMessageId,

        /** E.164, from the verified envelope. Never from message text (§6). */
        public string $fromPhone,

        /** Which of the center's numbers it arrived at — the routing key. */
        public string $phoneNumberId,

        public string $text,

        public CarbonImmutable $sentAt,
    ) {}
}
