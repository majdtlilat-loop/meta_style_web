<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Finds — or creates — the customer a booking belongs to.
 *
 * Booking does NOT own customer rules; the Customers module does. What this
 * action owns is the one rule Booking adds: a guest booking must attach to the
 * person who already exists, never make a second copy of them
 * (docs/13-ROADMAP.md Phase 6 §16).
 *
 * ## Why it does not simply call SaveCustomer
 *
 * `SaveCustomer` REFUSES when a phone already belongs to somebody, naming them,
 * so a receptionist can open the existing record and decide. That is right at a
 * desk and wrong on a public form: a guest typing their own number would be
 * refused by their own history, and the refusal would tell an anonymous
 * visitor that this number is a customer here — precisely the disclosure Phase
 * 5's masking exists to prevent (ADR-042).
 *
 * So guest resolution REUSES. It is the same normalisation and the same
 * identity rule as everywhere else in the product; only the collision
 * behaviour differs, and that difference is the whole reason this class exists
 * rather than a flag on the other one.
 *
 * ## What it never returns to the caller
 *
 * Nothing about a customer it found. If `+9647501234567` already belongs to
 * Sara, a guest booking with that number attaches to Sara's record and the
 * response echoes only what the guest typed. A guest cannot use this to learn a
 * name, and cannot use it to read Sara's appointments — those require her
 * account.
 */
final class ResolveBookingCustomer
{
    /**
     * @throws BookingFailed
     * @throws AuthorizationException
     */
    public function __invoke(CustomerRef $ref, BookingActor $actor): Customer
    {
        // A signed-in customer books for themselves. The reference carries no
        // identity at all, so there is nothing here to spoof (§27).
        if ($ref->isSelf) {
            return $this->own($actor);
        }

        if ($ref->uuid !== null) {
            return $this->existing($ref->uuid, $actor);
        }

        if (! $ref->hasDetails()) {
            throw BookingFailed::policy('A booking needs a customer.');
        }

        return $this->fromDetails($ref, $actor);
    }

    /**
     * @throws BookingFailed
     */
    private function own(BookingActor $actor): Customer
    {
        $customer = $actor->account?->customer;

        if (! $customer instanceof Customer || $customer->isArchived()) {
            throw BookingFailed::policy('This account cannot make a booking.');
        }

        return $customer;
    }

    /**
     * Selecting a customer by uuid — a staff-only capability.
     *
     * `customer.view` is required, because "book for customer X" against an
     * arbitrary uuid is a read of the customer list by another name. A guest
     * or a customer actor cannot reach this branch at all: they never carry a
     * uuid (§30).
     *
     * @throws BookingFailed
     * @throws AuthorizationException
     */
    private function existing(string $uuid, BookingActor $actor): Customer
    {
        if (! $actor->isStaff() || $actor->user === null) {
            throw BookingFailed::policy('A booking needs a customer.');
        }

        if (! $actor->user->hasPermission(Permission::CustomerView)) {
            throw new AuthorizationException('You may not look up customers.');
        }

        $customer = Customer::query()->where('uuid', $uuid)->first();

        if (! $customer instanceof Customer) {
            throw BookingFailed::policy('That customer was not found.');
        }

        if ($customer->isArchived()) {
            // Archived is a decision the center made about this person. A
            // booking would silently un-retire them.
            throw BookingFailed::policy('That customer is archived.');
        }

        return $customer;
    }

    /**
     * A name and a phone: a guest, or staff booking for somebody new.
     *
     * @throws BookingFailed
     * @throws AuthorizationException
     */
    private function fromDetails(CustomerRef $ref, BookingActor $actor): Customer
    {
        $phone = PhoneNumber::parse($ref->phone);

        if ($phone === null) {
            throw BookingFailed::policy('That does not look like a phone number.');
        }

        $existing = Customer::query()->where('phone', $phone->e164)->first();

        if ($existing instanceof Customer) {
            if ($existing->isArchived()) {
                // Refused in the same words a bad number would get. Saying
                // "that customer is archived" to an anonymous visitor would
                // confirm the number belongs to somebody here.
                throw BookingFailed::policy('We could not take that booking. Please contact the center.');
            }

            // ONE PERSON, ONE RECORD. Nothing about $existing is returned to a
            // guest caller; the appointment simply hangs off the right id, so
            // their history stays whole (§16).
            return $existing;
        }

        if ($actor->isStaff() && ($actor->user === null || ! $actor->user->hasPermission(Permission::CustomerCreate))) {
            throw new AuthorizationException('You may not add customers.');
        }

        $name = trim((string) $ref->name);

        if ($name === '') {
            /*
             * A NAME IS REQUIRED, and it is a privacy rule rather than a data
             * quality one. The obvious fallback — using the phone number as the
             * name — puts the number into the one customer field that is never
             * masked, where staff without `customer.contact.view` would read it
             * straight off a list, and into the audit trail's target label
             * (ADR-042).
             */
            throw BookingFailed::policy('A booking needs a name.');
        }

        /** @var Customer $customer */
        $customer = Customer::query()->create([
            'name' => $name,
            'phone' => $phone->e164,
            'phone_display' => $phone->display,
            'email' => $ref->email === null ? null : mb_strtolower($ref->email),
            'preferred_locale' => $ref->locale,
            // The catalog case Phase 5 reserved for exactly this moment.
            'source' => $actor->isStaff() ? CustomerSource::Staff : CustomerSource::Booking,
        ]);

        return $customer;
    }
}
