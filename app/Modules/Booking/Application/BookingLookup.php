<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Security\AttemptLimiter;
use App\Kernel\Security\Exceptions\MissingKeyVersion;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Modules\Booking\Domain\BookingReference;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\VerificationCode;
use SensitiveParameter;

/**
 * "Reference B-000412, code X4K7-9TB2M0" — resolved, or refused identically.
 *
 * The one place a booking is opened by capability rather than by identity, and
 * the whole design is in what it does NOT distinguish
 * (docs/24-BOOKING-VERIFICATION.md §9):
 *
 *   no such reference          → null
 *   reference in another tenant → null (it is not even queryable: the
 *                                 connection is this center's)
 *   right reference, no code    → null
 *   right reference, wrong code → null
 *   right reference, retired key → null, and REPORTED
 *
 * One answer, so the pair cannot be taken apart. If "no such booking" and
 * "wrong code" were distinguishable, the reference space — which is
 * deliberately enumerable — would become a way to map a center's entire book,
 * and the code would be the only thing left doing any work.
 *
 * ## What it grants
 *
 * THIS BOOKING. Nothing else (§9). Not the customer's other bookings, not their
 * history, not their invoices, loyalty, packages or memberships, and not their
 * account. Those need a verified customer identity, which a code is not: a code
 * proves someone holds one booking, not that they are the person who made it.
 * Callers that want more must resolve a Customer by some other means.
 *
 * ## Both buckets, always
 *
 * Attempts count against the REFERENCE being attacked and against whoever is
 * attacking it, and both are recorded on failure regardless of which part was
 * wrong — so a sweep across references trips the sender bucket long before any
 * single reference notices, and a grind against one reference trips that one
 * (§39). Counting only real failures, never successes, keeps a customer who
 * opens their own booking twice from limiting themselves.
 */
final class BookingLookup
{
    public function __construct(
        private readonly BookingVerification $verification,
        private readonly AttemptLimiter $limiter,
    ) {}

    /**
     * @param  string  $attemptedBy  who is trying — a verified E.164 sender, a
     *                               staff uuid. Fingerprinted before it is used
     *                               as a cache key; never stored.
     * @return Appointment|null the booking, or null for every kind of failure
     *
     * @throws TooManyAttempts when either bucket is already exhausted
     */
    public function byCode(
        string $presentedReference,
        #[SensitiveParameter] string $presentedCode,
        string $attemptedBy,
    ): ?Appointment {
        $reference = BookingReference::normalise($presentedReference);
        $code = VerificationCode::normalise($presentedCode);

        /*
         * Shape first, and refused WITHOUT counting an attempt.
         *
         * Garbage — an empty string, a uuid pasted into the wrong box, a
         * fourteen-character code — is not a guess at anything, and letting it
         * consume the allowance would let an attacker lock a real customer out
         * of their own booking by spraying nonsense at its reference.
         */
        if (! BookingReference::isWellFormed($reference) || ! VerificationCode::isWellFormed($code)) {
            return null;
        }

        $this->limiter->assertAllowed('booking_code.reference', $reference);
        $this->limiter->assertAllowed('booking_code.sender', $attemptedBy);

        /** @var Appointment|null $appointment */
        $appointment = Appointment::query()->where('reference', $reference)->first();

        if ($appointment instanceof Appointment && $this->matches($appointment, $code)) {
            return $appointment;
        }

        $this->limiter->record('booking_code.reference', $reference);
        $this->limiter->record('booking_code.sender', $attemptedBy);

        return null;
    }

    /**
     * Fails closed AND reports when this deployment cannot check the code.
     *
     * A booking whose key version has been retired is not a wrong code — it is
     * a booking nobody can verify — and the customer must not be told the
     * difference, because it is a fact about the deployment rather than about
     * them. Swallowing it silently would be the real bug: every affected
     * customer would simply be refused, forever, with nothing anywhere
     * recording why (§§5, 7).
     */
    private function matches(Appointment $appointment, #[SensitiveParameter] string $code): bool
    {
        try {
            return $this->verification->matches($appointment, $code);
        } catch (MissingKeyVersion $e) {
            report($e);

            return false;
        }
    }
}
