<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

/**
 * Everything one verified notification contained.
 *
 * Meta BATCHES: a single POST carries `entry[]`, each with `changes[]`, each
 * with a `value` that may hold several `messages` and several `statuses`. A
 * parser that returned "the message" would silently drop the rest of a burst
 * during a busy hour — and the drop would be invisible, because the webhook
 * still answered 200 (docs/25-WHATSAPP.md §10).
 *
 * So an adapter returns everything it found, and the caller processes each item
 * independently and idempotently.
 */
final readonly class InboundBatch
{
    /**
     * @param  list<InboundMessage>  $messages
     * @param  list<InboundStatus>  $statuses
     */
    public function __construct(
        public array $messages = [],
        public array $statuses = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->messages === [] && $this->statuses === [];
    }
}
