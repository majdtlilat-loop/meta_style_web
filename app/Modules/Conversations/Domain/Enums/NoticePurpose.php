<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Enums;

/**
 * Why the center is messaging a customer who did not write first.
 *
 * Each purpose maps to ONE approved template, by name, in
 * `config/whatsapp.php` `templates` (docs/25-WHATSAPP.md §14). A purpose with
 * no mapping cannot be sent: business-initiated messages never fall back to
 * free-form text, which Meta refuses outside the customer service window.
 */
enum NoticePurpose: string
{
    case BookingConfirmation = 'booking_confirmation';

    /** The `config/whatsapp.php` `templates` key. */
    public function templateKey(): string
    {
        return $this->value;
    }
}
