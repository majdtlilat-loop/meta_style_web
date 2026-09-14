<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A signed-in customer's own bookings.
 *
 * ## Identity comes from the guard, never from the request
 *
 * Every method here derives the customer from the authenticated account. A
 * `customer` field in a request body is not read, not validated and not
 * honoured — "book this for customer X" from a customer session is a request to
 * act as somebody else, and the only safe answer is to make it inexpressible
 * (docs/13-ROADMAP.md Phase 6 §27).
 *
 * ## Another customer's appointment is NOT FOUND, not forbidden
 *
 * A 403 on a real uuid confirms the appointment exists. Both cases answer 404,
 * so a customer cannot walk uuids to discover who else has bookings
 * (docs/08-AUDIT-SECURITY.md §19).
 *
 * Runs under `auth:customer-api`, so a STAFF token cannot reach these routes —
 * Sanctum compares the token's owner against the guard's provider model
 * (ADR-041).
 */
final class CustomerBookingController extends Controller
{
    public function availability(Request $request, AvailabilityEngine $engine): JsonResponse
    {
        $this->account($request);

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
            // A customer sees the same catalog a guest does. Having an account
            // is not a reason to be offered a service the center said must be
            // booked by phone.
            publicChannel: true,
        );

        return ApiResponse::data([
            'slots' => array_map(static fn (AvailabilitySlot $slot): array => $slot->toArray(), $slots),
        ]);
    }

    public function index(Request $request, CalendarQuery $calendar, AppointmentPresenter $presenter): JsonResponse
    {
        $account = $this->account($request);

        $upcoming = ! $request->boolean('past');

        $appointments = $calendar->forCustomer((int) $account->customer_id, $upcoming);

        return ApiResponse::data([
            'appointments' => $appointments
                ->map(fn (Appointment $a): array => $presenter->forCustomer($a))
                ->values()->all(),
        ]);
    }

    public function show(string $uuid, Request $request, AppointmentPresenter $presenter): JsonResponse
    {
        $account = $this->account($request);

        $appointment = $this->find($uuid, $account);

        return ApiResponse::data($presenter->forCustomer($appointment->load(['items.employee', 'items.addons', 'branch'])));
    }

    public function store(Request $request, BookingEngine $engine, AppointmentPresenter $presenter): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'branch' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'services' => ['required', 'array', 'min:1', 'max:5'],
            'services.*.service' => ['required', 'string', 'max:64'],
            'services.*.variation' => ['nullable', 'string', 'max:64'],
            'services.*.employee' => ['nullable', 'string', 'max:64'],
            'services.*.addons' => ['nullable', 'array', 'max:10'],
            'services.*.addons.*' => ['string', 'max:64'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $appointment = $engine->book(
            new BookingRequest(
                branchUuid: (string) $data['branch'],
                lines: BookingLine::listFromArray($data['services']),
                startsAt: CarbonImmutable::parse((string) $data['starts_at'])->utc(),
                // `self()`, unconditionally. No uuid is read from the body.
                customer: CustomerRef::self(),
                customerNote: $data['note'] ?? null,
            ),
            BookingActor::customer($account, $this->label($account)),
        );

        return ApiResponse::data($presenter->forCustomer($appointment->load(['items.employee', 'items.addons', 'branch'])), 201);
    }

    public function reschedule(
        string $uuid,
        Request $request,
        BookingEngine $engine,
        AppointmentPresenter $presenter,
    ): JsonResponse {
        $account = $this->account($request);

        $data = $request->validate(['starts_at' => ['required', 'date']]);

        $appointment = $engine->reschedule(
            $this->find($uuid, $account),
            CarbonImmutable::parse((string) $data['starts_at'])->utc(),
            BookingActor::customer($account, $this->label($account)),
        );

        return ApiResponse::data($presenter->forCustomer($appointment->load(['items.employee', 'items.addons', 'branch'])));
    }

    public function cancel(
        string $uuid,
        Request $request,
        BookingEngine $engine,
        AppointmentPresenter $presenter,
    ): JsonResponse {
        $account = $this->account($request);

        $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $appointment = $engine->cancel(
            $this->find($uuid, $account),
            BookingActor::customer($account, $this->label($account)),
            $request->string('reason')->toString() ?: null,
        );

        return ApiResponse::data($presenter->forCustomer($appointment->load(['items.employee', 'items.addons', 'branch'])));
    }

    /**
     * Scoped to the account's own customer, in the QUERY.
     *
     * Not loaded and then checked: a `where` clause cannot be forgotten by the
     * next person who adds a branch to this method.
     */
    private function find(string $uuid, CustomerAccount $account): Appointment
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::query()
            ->where('uuid', $uuid)
            ->where('customer_id', $account->customer_id)
            ->firstOrFail();

        return $appointment;
    }

    private function label(CustomerAccount $account): string
    {
        $customer = $account->relationLoaded('customer') ? $account->customer : $account->customer()->first();

        return $customer instanceof Customer ? $customer->name : 'customer';
    }

    /**
     * @throws AuthorizationException
     */
    private function account(Request $request): CustomerAccount
    {
        $account = $request->user('customer-api');

        if (! $account instanceof CustomerAccount) {
            throw new AuthorizationException('Authentication is required.');
        }

        return $account;
    }
}
