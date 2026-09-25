<?php

declare(strict_types=1);

namespace App\Modules\Booking\Data;

use App\Kernel\Localization\TranslatedText;

/**
 * One booked service, as a confirmation may name it.
 *
 * The SNAPSHOT names the booking holds — a later catalog rename never rewrites
 * what was booked (docs/15-BOOKING.md). Translatable, so the reader chooses the
 * language. No price, no duration, no id.
 */
final readonly class BookingConfirmationLine
{
    /**
     * @param  list<TranslatedText>  $addons  in booked order
     * @param  TranslatedText|null  $employee  only when the customer asked for
     *                                         that person by name; an "anyone
     *                                         available" assignment is a
     *                                         scheduling detail the center may
     *                                         still change
     */
    public function __construct(
        public TranslatedText $service,
        public ?TranslatedText $variation,
        public array $addons,
        public ?TranslatedText $employee,
    ) {}
}
