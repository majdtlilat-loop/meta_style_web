<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Availability and guest booking from the public electronic menu.
 *
 * ## A WRITE on a path-resolved surface, and why that is acceptable
 *
 * ADR-036 allows the center's public key in the URL for the guest menu, on the
 * strict condition that it never becomes a way to ACT AS a center. This
 * endpoint writes, so the condition deserves re-examining rather than assuming.
 *
 * What a caller can do here is create a booking for themselves — the same thing
 * they could do by walking in. They cannot read a center's data, cannot reach
 * an authenticated surface, cannot choose a branch the center has not
 * published, cannot book a service marked "call to book", and cannot claim a
 * privileged source. The route carries no authentication middleware, which an
 * architecture test enforces; it is entitlement-gated, throttled per center and
 * per address, and idempotent.
 *
 * So the trust level is unchanged: this is a customer acting as a customer, on
 * the surface built for customers (docs/13-ROADMAP.md Phase 6 §26).
 *
 * ## What it deliberately does not return
 *
 * If the phone number given already belongs to an existing customer, the
 * booking attaches to that record — but the response echoes nothing about them.
 * A guest cannot use this endpoint to learn a name, and cannot use the returned
 * uuid to read anything else, because reading an appointment requires an
 * account (§16, ADR-042).
 */
final class PublicBookingController extends Controller
{
    public function availability(Request $request, AvailabilityEngine $engine): JsonResponse
    {
        $data = $request->validate([
            'branch' => ['required', 'string', 'max:64'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'services' => ['required', 'array', 'min:1', 'max:5'],
            'services.*.service' => ['required', 'string', 'max:64'],
            'services.*.variation' => ['nullable', 'string', 'max:64'],
            'services.*.employee' => ['nullable', 'string', 'max:64'],
            'services.*.addons' => ['nullable', 'array', 'max:10'],
            'services.*.addons.*' => ['string', 'max:64'],
        ]);

        $slots = $engine->slots(
            new AvailabilityQuery(
                branchUuid: (string) $data['branch'],
                lines: BookingLine::listFromArray($data['services']),
                fromDate: (string) $data['from'],
                toDate: (string) ($data['to'] ?? $data['from']),
            ),
            // The public view: published branches, online-bookable services.
            publicChannel: true,
        );

        return ApiResponse::data([
            'slots' => array_map(static fn (AvailabilitySlot $slot): array => $slot->toArray(), $slots),
        ]);
    }

    public function store(Request $request, BookingEngine $engine, AppointmentPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'branch' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'services' => ['required', 'array', 'min:1', 'max:5'],
            'services.*.service' => ['required', 'string', 'max:64'],
            'services.*.variation' => ['nullable', 'string', 'max:64'],
            'services.*.employee' => ['nullable', 'string', 'max:64'],
            'services.*.addons' => ['nullable', 'array', 'max:10'],
            'services.*.addons.*' => ['string', 'max:64'],
            // A name is required, and it is a privacy rule: without one the
            // phone number would end up in the customer's name field, which is
            // never masked (ADR-042).
            'name' => ['required', 'string', 'max:190'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $booked = $engine->book(
            new BookingRequest(
                branchUuid: (string) $data['branch'],
                lines: BookingLine::listFromArray($data['services']),
                startsAt: CarbonImmutable::parse((string) $data['starts_at'])->utc(),
                customer: CustomerRef::details(
                    (string) $data['name'],
                    (string) $data['phone'],
                    is_string($data['email'] ?? null) ? $data['email'] : null,
                    app()->getLocale(),
                ),
                customerNote: $data['note'] ?? null,
            ),
            /*
             * ALWAYS a guest actor. There is no path by which a request body
             * can make this `staff` — the source is a property of the endpoint,
             * not of the payload (§15).
             */
            BookingActor::guest(),
        );

        return ApiResponse::data(
            // The customer shape, not the staff one: no internal notes, no
            // source, no record of who created it — plus the verification code,
            // which a guest has no other way of ever obtaining: they have no
            // account to regenerate it from (docs/24-BOOKING-VERIFICATION.md §8).
            $presenter->withVerificationCode(
                $presenter->forCustomer($booked->appointment->load(['items.employee', 'items.addons', 'branch'])),
                $booked->verificationCode,
            ),
            201,
        );
    }
}
