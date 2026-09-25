<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

/**
 * Which way a message went.
 *
 * Separate from {@see MessageAuthor} on purpose. Direction is about the wire —
 * it decides whether a delivery state means anything — and the author is about
 * accountability. They are not interchangeable: a `system` note and a `staff`
 * reply are both outbound, and only one of them was sent by a person.
 */
enum MessageDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    /**
     * Only outbound messages have a delivery state. Something that ARRIVED has
     * no delivery outcome of ours to report (docs/25-WHATSAPP.md §9).
     */
    public function tracksDelivery(): bool
    {
        return $this === self::Outbound;
    }
}
