<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Modules\Booking\Domain\Models\Appointment;
use SensitiveParameter;

/**
 * What creating a booking produces: the appointment, and the ONE chance to read
 * its verification code.
 *
 * ## Why booking returns a result object rather than an Appointment
 *
 * The verification code is a capability that exists for a single moment. It is
 * never stored raw, so there is no later read that could produce it — the only
 * place it can be handed over is the return of the call that created it.
 *
 * Carrying it back on the model was considered and rejected: a transient
 * property on an Eloquent object is invisible at the call site, survives no
 * refresh, and would make the delivery of a secret depend on nobody calling
 * `->fresh()`. A DTO makes it a fact of the signature — a channel either reads
 * `$result->verificationCode` or it does not, and that is a decision somebody
 * made rather than one that happened (docs/24-BOOKING-VERIFICATION.md §3).
 *
 * ## A channel is allowed to ignore it
 *
 * WhatsApp does, deliberately. Every booking gets a code at the Booking-domain
 * level, but the WhatsApp channel never sends or echoes it — so
 * "a digest exists" must never be read as "the customer has the code" (§12).
 * A customer who later needs it regenerates through an authorised path.
 */
final readonly class BookingResult
{
    public function __construct(
        public Appointment $appointment,
        /**
         * The raw code, in the clear, for this return only.
         *
         * Null when the appointment carries no code — which today means a
         * LEGACY booking created before Phase 13 and never issued one. A new
         * booking always has it.
         */
        #[SensitiveParameter]
        public ?string $verificationCode = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'appointment' => $this->appointment->uuid,
            'verificationCode' => $this->verificationCode === null ? null : '[redacted]',
        ];
    }
}
