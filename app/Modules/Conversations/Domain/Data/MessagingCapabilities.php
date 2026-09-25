<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

/**
 * What one messaging adapter can actually do — stated, so nothing pretends.
 *
 * The same contract Payments established for gateways (`ProviderCapabilities`)
 * and for the same reason: generic code above the adapter has to make decisions
 * — may I retry this? may I ask the provider what happened? do I need an
 * approved template here? — and the honest answer differs per provider. A
 * default of "yes" would mean the first adapter that cannot do something
 * silently corrupts a conversation (docs/25-WHATSAPP.md §5).
 */
final readonly class MessagingCapabilities
{
    public function __construct(
        /** A verified adapter exists and the account may be configured at all. */
        public bool $available,

        /** The provider signs its webhooks, so a notification can be TRUSTED. */
        public bool $verifiesSignature,

        /** Approved templates exist and can be sent (§14). */
        public bool $templates,

        /**
         * The provider accepts a caller-supplied idempotency key on send, so a
         * retry provably cannot duplicate a message.
         *
         * FALSE for Meta Cloud, verified against its documentation: the send
         * endpoint takes no such key. That is exactly why an ambiguous outcome
         * stays `unknown` rather than being retried (§9).
         */
        public bool $idempotentSend,

        /**
         * The provider can be asked, after the fact, what happened to a message
         * — turning an `unknown` into a fact.
         *
         * FALSE for Meta Cloud: delivery is reported by CALLBACK, and there is
         * no documented endpoint to read a single message's status back. So an
         * `unknown` there is resolved by a later status callback arriving, or
         * by a human, and never by a lookup this code invents (§11).
         */
        public bool $statusLookup,
    ) {}

    public static function unavailable(): self
    {
        return new self(false, false, false, false, false);
    }
}
