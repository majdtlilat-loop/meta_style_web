<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteAdvisory;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Booking\Application\Actions\ManageAppointmentNotes;
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
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff booking API.
 *
 * An ADAPTER, nothing more: it validates input, builds the engine's DTOs, calls
 * one method, and presents the result. It computes no slot, applies no policy
 * and never writes to `appointments` — that boundary is what stops each channel
 * growing its own idea of the rules (docs/04-MODULE-BOUNDARIES.md §4.1).
 *
 * The booking SOURCE is not read from the request. It comes from the actor this
 * controller constructs, which is always staff, so a client cannot mislabel
 * where a booking came from (docs/13-ROADMAP.md Phase 6 §15).
 *
 * Uuids on the wire, never internal ids (docs/08-AUDIT-SECURITY.md).
 */
final class AppointmentController extends Controller
{
    // ---------------------------------------------------------- availability

    public function availability(Request $request, AvailabilityEngine $engine): JsonResponse
    {
        $user = $this->user($request);

        $this->require($user, Permission::AppointmentView, 'You may not view appointments.');

        $data = $request->validate($this->availabilityRules());

        $slots = $engine->slots(
            new AvailabilityQuery(
                branchUuid: (string) $data['branch'],
                lines: BookingLine::listFromArray($data['services']),
                fromDate: (string) $data['from'],
                toDate: (string) ($data['to'] ?? $data['from']),
            ),
            // Staff are not bound by the public rules: they book services a
            // center has marked "call to book", and at branches not published
            // on the menu.
            publicChannel: false,
        );

        return ApiResponse::data([
            'slots' => array_map(static fn (AvailabilitySlot $slot): array => $slot->toArray(), $slots),
        ]);
    }

    // -------------------------------------------------------------- calendar

    public function calendar(Request $request, CalendarQuery $calendar, AppointmentPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'string', 'max:64'],
            'employee' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'string', 'max:24'],
        ]);

        $appointments = $calendar->forRange(
            (string) $data['from'],
            (string) ($data['to'] ?? $data['from']),
            $user,
            [
                'branch' => $request->string('branch')->toString() ?: null,
                'employee' => $request->string('employee')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
            ],
        );

        return ApiResponse::data([
            'appointments' => $appointments
                ->map(fn (Appointment $a): array => $presenter->summary($a, $user))
                ->values()->all(),
        ]);
    }

    /**
     * Future appointments assigned to an employee who is no longer active.
     *
     * The operational answer to "we deactivated Ahmed — who was booked with
     * him?". Nothing is reassigned automatically (§21).
     */
    public function affected(Request $request, CalendarQuery $calendar, AppointmentPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        return ApiResponse::data([
            'appointments' => $calendar->affectedByInactiveEmployees($user)
                ->map(fn (Appointment $a): array => $presenter->summary($a, $user))
                ->values()->all(),
        ]);
    }

    // ------------------------------------------------------------- lifecycle

    public function store(Request $request, BookingEngine $engine, AppointmentPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'branch' => ['required', 'string', 'max:64'],
            'starts_at' => ['required', 'date'],
            'services' => ['required', 'array', 'min:1', 'max:10'],
            'services.*.service' => ['required', 'string', 'max:64'],
            'services.*.variation' => ['nullable', 'string', 'max:64'],
            'services.*.employee' => ['nullable', 'string', 'max:64'],
            'services.*.addons' => ['nullable', 'array', 'max:10'],
            'services.*.addons.*' => ['string', 'max:64'],
            'services.*.note' => ['nullable', 'string', 'max:500'],
            // Either an existing customer, or the details to resolve one.
            'customer' => ['nullable', 'string', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:190'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_email' => ['nullable', 'email', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $appointment = $engine->book(
            new BookingRequest(
                branchUuid: (string) $data['branch'],
                lines: BookingLine::listFromArray($data['services']),
                // ISO-8601 with offset, converted to UTC here so the engine
                // never sees an ambiguous wall clock
                // (docs/10-API-FOUNDATION.md §8).
                startsAt: CarbonImmutable::parse((string) $data['starts_at'])->utc(),
                customer: $this->customerRef($data),
                customerNote: $data['note'] ?? null,
            ),
            BookingActor::staff($user),
        );

        return ApiResponse::data(
            $presenter->detail($this->loaded($appointment), $user),
            201,
        );
    }

    public function show(string $uuid, Request $request, AppointmentPresenter $presenter): JsonResponse
    {
        $user = $this->user($request);

        $this->require($user, Permission::AppointmentView, 'You may not view appointments.');

        $appointment = $this->find($uuid, $user);

        return ApiResponse::data($presenter->detail($this->loaded($appointment), $user));
    }

    public function reschedule(
        string $uuid,
        Request $request,
        BookingEngine $engine,
        AppointmentPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $data = $request->validate(['starts_at' => ['required', 'date']]);

        $appointment = $engine->reschedule(
            $this->find($uuid, $user),
            CarbonImmutable::parse((string) $data['starts_at'])->utc(),
            BookingActor::staff($user),
        );

        return ApiResponse::data($presenter->detail($this->loaded($appointment), $user));
    }

    public function confirm(string $uuid, Request $request, BookingEngine $engine, AppointmentPresenter $p): JsonResponse
    {
        return $this->move($uuid, AppointmentStatus::Confirmed, $request, $engine, $p);
    }

    public function complete(string $uuid, Request $request, BookingEngine $engine, AppointmentPresenter $p): JsonResponse
    {
        return $this->move($uuid, AppointmentStatus::Completed, $request, $engine, $p);
    }

    public function noShow(string $uuid, Request $request, BookingEngine $engine, AppointmentPresenter $p): JsonResponse
    {
        return $this->move($uuid, AppointmentStatus::NoShow, $request, $engine, $p);
    }

    public function cancel(
        string $uuid,
        Request $request,
        BookingEngine $engine,
        AppointmentPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $appointment = $engine->cancel(
            $this->find($uuid, $user),
            BookingActor::staff($user),
            $request->string('reason')->toString() ?: null,
        );

        return ApiResponse::data($presenter->detail($this->loaded($appointment), $user));
    }

    // ----------------------------------------------------------------- notes

    public function storeNote(string $uuid, Request $request, ManageAppointmentNotes $notes): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'visibility' => ['nullable', 'string', 'in:internal,manager_only'],
        ]);

        $note = $notes->add(
            $this->find($uuid, $user),
            (string) $data['body'],
            $user,
            NoteVisibility::tryFrom((string) ($data['visibility'] ?? 'internal')) ?? NoteVisibility::Internal,
        );

        return ApiResponse::data(
            ['uuid' => $note->uuid, 'visibility' => $note->visibility->value],
            201,
            // The advisory travels with the API, not just the web form: a mobile
            // client that never reads the docs still gets a string to render
            // above its own text box (§1).
            meta: NoteAdvisory::meta(),
        );
    }

    public function destroyNote(string $uuid, string $noteUuid, Request $request, ManageAppointmentNotes $notes): JsonResponse
    {
        $user = $this->user($request);

        $appointment = $this->find($uuid, $user);

        $note = InternalNote::query()
            ->where('uuid', $noteUuid)
            ->where('owner_type', NoteOwner::Appointment->value)
            ->firstOrFail();

        $notes->delete($appointment, $note, $user);

        return ApiResponse::data(['deleted' => true]);
    }

    // ------------------------------------------------------------- internals

    /**
     * @return array<string, mixed>
     */
    private function availabilityRules(): array
    {
        return [
            'branch' => ['required', 'string', 'max:64'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'services' => ['required', 'array', 'min:1', 'max:10'],
            'services.*.service' => ['required', 'string', 'max:64'],
            'services.*.variation' => ['nullable', 'string', 'max:64'],
            'services.*.employee' => ['nullable', 'string', 'max:64'],
            'services.*.addons' => ['nullable', 'array', 'max:10'],
            'services.*.addons.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BookingFailed
     */
    private function customerRef(array $data): CustomerRef
    {
        $uuid = $data['customer'] ?? null;

        if (is_string($uuid) && $uuid !== '') {
            return CustomerRef::existing($uuid);
        }

        $name = $data['customer_name'] ?? null;
        $phone = $data['customer_phone'] ?? null;

        if (is_string($name) && is_string($phone) && trim($name) !== '' && trim($phone) !== '') {
            return CustomerRef::details(
                $name,
                $phone,
                is_string($data['customer_email'] ?? null) ? $data['customer_email'] : null,
            );
        }

        throw BookingFailed::policy('A booking needs a customer.');
    }

    private function move(
        string $uuid,
        AppointmentStatus $target,
        Request $request,
        BookingEngine $engine,
        AppointmentPresenter $presenter,
    ): JsonResponse {
        $user = $this->user($request);

        $appointment = $engine->transition($this->find($uuid, $user), $target, BookingActor::staff($user));

        return ApiResponse::data($presenter->detail($this->loaded($appointment), $user));
    }

    /**
     * Loads exactly what the presenter renders.
     *
     * Explicit, because `preventLazyLoading` is on outside production: a
     * relation the presenter touches and this method forgot fails the test
     * suite rather than issuing a silent extra query in production.
     */
    private function loaded(Appointment $appointment): Appointment
    {
        return $appointment->load(['customer', 'branch', 'items.employee', 'items.addons', 'internalNotes']);
    }

    /**
     * @throws AuthorizationException
     */
    private function find(string $uuid, User $user): Appointment
    {
        /** @var Appointment $appointment */
        $appointment = Appointment::query()->where('uuid', $uuid)->firstOrFail();

        // Branch scope is authorization, not a filter. A 403 rather than a 404
        // is safe here: the appointment is in THIS tenant, and the caller
        // already holds appointment permissions somewhere in the center.
        if (! $user->canAccessBranch((int) $appointment->branch_id)) {
            throw new AuthorizationException('You may not view that branch.');
        }

        return $appointment;
    }

    /**
     * @throws AuthorizationException
     */
    private function require(User $user, Permission $permission, string $message): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($message);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Authentication is required.');
        }

        return $user;
    }
}
