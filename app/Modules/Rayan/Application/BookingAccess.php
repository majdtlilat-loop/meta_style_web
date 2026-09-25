<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application;

use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Modules\Booking\Application\BookingLookup;
use App\Modules\Booking\Domain\BookingReference;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Rayan\Domain\Data\ToolContext;

/**
 * Which booking may this conversation act on, and on what authority?
 *
 * Shared by the three tools that name ONE appointment, because the rule is the
 * same for all of them and writing it three times is how two of them drift
 * (docs/27-RAYAN.md §12, docs/24-BOOKING-VERIFICATION.md §9).
 *
 * ## Exactly two ways in
 *
 *   OWNERSHIP    the conversation resolved to a customer, and the booking is
 *                theirs. The authority is the signature-verified phone number
 *                that identified them (ADR-070).
 *   CAPABILITY   the caller supplied the booking's reference AND its
 *                verification code. That grants access to THAT BOOKING and
 *                nothing else — not the customer's other bookings, not their
 *                history, not their invoices (§9).
 *
 * ## And the ways that are deliberately closed
 *
 * A reference ALONE is not one. It is public and enumerable by design, so if it
 * granted access it would be the code. A reference plus a typed phone number is
 * not one either: a phone number is printed on business cards.
 *
 * ## The same answer for every failure
 *
 * No such booking, somebody else's booking, wrong code — all null. Telling the
 * difference apart would turn the enumerable reference space into a map of the
 * center's book, and it would leak whether a given reference exists to anybody
 * who can send a WhatsApp message.
 */
final class BookingAccess
{
    public function __construct(private readonly BookingLookup $lookup) {}

    /**
     * @param  string|null  $code  a verification code the customer supplied in
     *                             conversation, when they are not the resolved
     *                             owner
     * @return Appointment|null null for every kind of refusal
     */
    public function resolve(string $presentedReference, ?string $code, ToolContext $context): ?Appointment
    {
        $reference = BookingReference::normalise($presentedReference);

        if (! BookingReference::isWellFormed($reference)) {
            return null;
        }

        if ($context->isIdentified()) {
            /** @var Appointment|null $owned */
            $owned = Appointment::query()
                ->where('reference', $reference)
                // The ownership check IS the query. A booking belonging to
                // somebody else is simply not found, so there is no branch
                // where a wrong answer could be returned by mistake.
                ->where('customer_id', $context->customerId)
                ->first();

            if ($owned instanceof Appointment) {
                return $owned;
            }
        }

        if ($code === null || $code === '') {
            return null;
        }

        try {
            /*
             * The capability path. Rate limited per reference AND per sender
             * inside the lookup, so a conversation cannot be used to grind
             * through codes — and the verified phone is what identifies the
             * attacker, which is the bucket that actually bounds a sweep across
             * many references (§39).
             */
            return $this->lookup->byCode($reference, $code, $context->verifiedPhone);
        } catch (TooManyAttempts) {
            // Refused the same way a wrong code is, so being rate limited does
            // not itself reveal that the reference exists.
            return null;
        }
    }
}
