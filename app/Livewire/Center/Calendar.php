<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
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
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Employees\Domain\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The staff calendar, and the booking desk.
 *
 * ## A table, not a calendar library
 *
 * Day and week views are rendered as ordinary HTML tables. A JavaScript
 * calendar would need its own tenant-aware endpoint, its own RTL handling and
 * its own build step to draw what Blade already draws — and this product must
 * work in Arabic, Kurdish and English, right to left and left to right, on a
 * phone in a salon. Logical CSS properties do that for free; a canvas-based
 * calendar does not (docs/13-ROADMAP.md Phase 6 §23).
 *
 * ## Nothing here decides anything
 *
 * Availability comes from {@see AvailabilityEngine}; every mutation goes
 * through {@see BookingEngine}. This component gathers input and renders a
 * result. If a rule appeared in this file it would be a rule the API and the
 * public form did not have (docs/04-MODULE-BOUNDARIES.md §4.1).
 *
 * Masking is not done here either: every appointment is rendered through
 * {@see AppointmentPresenter}, which is what the API uses (ADR-042).
 */
#[Layout('components.layouts.app')]
final class Calendar extends Component
{
    #[Url]
    public string $date = '';

    /** `day` or `week`. Month is deliberately absent — see the view. */
    #[Url]
    public string $view = 'day';

    #[Url(as: 'branch')]
    public string $branchUuid = '';

    #[Url(as: 'employee')]
    public string $employeeUuid = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    /** Show only appointments whose assigned employee is now inactive. */
    #[Url]
    public bool $affected = false;

    // ------------------------------------------------------------ booking form

    public bool $booking = false;

    public string $customerSearch = '';

    public string $customerUuid = '';

    public string $newCustomerName = '';

    public string $newCustomerPhone = '';

    public string $serviceUuid = '';

    public string $variationUuid = '';

    /** @var list<string> */
    public array $addonUuids = [];

    public string $bookingEmployeeUuid = '';

    public string $bookingDate = '';

    public string $bookingNote = '';

    /** @var list<array<string, string>> */
    public array $slots = [];

    // -------------------------------------------------------------- appointment

    public ?string $viewing = null;

    public bool $rescheduling = false;

    public string $noteBody = '';

    public string $noteVisibility = 'internal';

    public string $cancelReason = '';

    public string $notice = '';

    public string $error = '';

    public function mount(): void
    {
        $this->date = $this->date !== '' ? $this->date : CarbonImmutable::now()->format('Y-m-d');
        $this->bookingDate = $this->date;
    }

    // ------------------------------------------------------------- navigation

    public function move(int $days): void
    {
        $this->date = CarbonImmutable::parse($this->date)->addDays($days)->format('Y-m-d');
        $this->viewing = null;
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now()->format('Y-m-d');
        $this->viewing = null;
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'week' ? 'week' : 'day';
        $this->viewing = null;
    }

    // ---------------------------------------------------------------- booking

    public function startBooking(): void
    {
        $this->resetBookingForm();
        $this->booking = true;
        $this->viewing = null;
        $this->bookingDate = $this->date;
    }

    public function cancelBooking(): void
    {
        $this->booking = false;
        $this->resetBookingForm();
    }

    /**
     * Selects an existing customer from the search results.
     */
    public function chooseCustomer(string $uuid): void
    {
        $this->customerUuid = $uuid;
        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
    }

    public function findSlots(AvailabilityEngine $engine): void
    {
        $this->slots = [];
        $this->error = '';

        if ($this->serviceUuid === '' || $this->branchUuid === '') {
            $this->error = __('Choose a branch and a service first.');

            return;
        }

        try {
            $found = $engine->slots(
                new AvailabilityQuery(
                    branchUuid: $this->branchUuid,
                    lines: [$this->line()],
                    fromDate: $this->bookingDate,
                    toDate: $this->bookingDate,
                ),
                publicChannel: false,
            );
        } catch (BookingFailed $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->slots = array_map(
            static fn (AvailabilitySlot $slot): array => [
                'starts_at' => $slot->startsAt->toIso8601String(),
                'time' => $slot->localTime,
            ],
            $found,
        );

        if ($this->slots === []) {
            $this->error = __('Nothing is available that day.');
        }
    }

    public function book(string $startsAt, BookingEngine $engine): void
    {
        $this->error = '';

        try {
            $appointment = $engine->book(
                new BookingRequest(
                    branchUuid: $this->branchUuid,
                    lines: [$this->line()],
                    startsAt: CarbonImmutable::parse($startsAt)->utc(),
                    customer: $this->customerRef(),
                    customerNote: $this->bookingNote === '' ? null : $this->bookingNote,
                ),
                BookingActor::staff($this->user()),
            );
        } catch (BookingFailed|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->booking = false;
        $this->resetBookingForm();
        $this->viewing = $appointment->uuid;
        $this->date = $appointment->localDate();
        $this->notice = __('Booked.');
    }

    // ------------------------------------------------------------- lifecycle

    public function open(string $uuid): void
    {
        $this->viewing = $uuid;
        $this->booking = false;
        $this->rescheduling = false;
        $this->error = '';
    }

    public function close(): void
    {
        $this->viewing = null;
        $this->rescheduling = false;
    }

    public function confirm(BookingEngine $engine): void
    {
        $this->transition($engine, AppointmentStatus::Confirmed, __('Confirmed.'));
    }

    public function complete(BookingEngine $engine): void
    {
        $this->transition($engine, AppointmentStatus::Completed, __('Marked completed.'));
    }

    public function noShow(BookingEngine $engine): void
    {
        $this->transition($engine, AppointmentStatus::NoShow, __('Marked as a no-show.'));
    }

    public function cancel(BookingEngine $engine): void
    {
        $appointment = $this->current();

        if (! $appointment instanceof Appointment) {
            return;
        }

        try {
            $engine->cancel(
                $appointment,
                BookingActor::staff($this->user()),
                $this->cancelReason === '' ? null : $this->cancelReason,
            );
        } catch (BookingFailed|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->cancelReason = '';
        $this->notice = __('Cancelled.');
    }

    public function startReschedule(AvailabilityEngine $engine): void
    {
        $appointment = $this->current();

        if (! $appointment instanceof Appointment) {
            return;
        }

        $this->rescheduling = true;
        $this->bookingDate = $appointment->localDate();
        $this->branchUuid = (string) $appointment->branch()->value('uuid');

        $this->slotsForExisting($appointment, $engine);
    }

    public function rescheduleTo(string $startsAt, BookingEngine $engine): void
    {
        $appointment = $this->current();

        if (! $appointment instanceof Appointment) {
            return;
        }

        try {
            $engine->reschedule(
                $appointment,
                CarbonImmutable::parse($startsAt)->utc(),
                BookingActor::staff($this->user()),
            );
        } catch (BookingFailed|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->rescheduling = false;
        $this->slots = [];
        $this->date = $appointment->refresh()->localDate();
        $this->notice = __('Moved.');
    }

    public function addNote(ManageAppointmentNotes $notes): void
    {
        $appointment = $this->current();

        if (! $appointment instanceof Appointment) {
            return;
        }

        try {
            $notes->add(
                $appointment,
                $this->noteBody,
                $this->user(),
                NoteVisibility::tryFrom($this->noteVisibility) ?? NoteVisibility::Internal,
            );
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->noteBody = '';
        $this->notice = __('Note saved.');
    }

    // ------------------------------------------------------------------ render

    public function render(
        CalendarQuery $calendar,
        AppointmentPresenter $presenter,
    ): mixed {
        $user = $this->user();

        $branches = Branch::query()->active()->orderBy('sort_order')->orderBy('id')->get();

        // Default to the first branch in scope rather than "all": a day view
        // mixing two branches' schedules is unreadable, and reception works at
        // one desk.
        $first = $branches->first();

        if ($this->branchUuid === '' && $first instanceof Branch) {
            $this->branchUuid = (string) $first->uuid;
        }

        [$from, $to] = $this->range();

        $appointments = collect();
        $error = $this->error;

        try {
            $appointments = $this->affected
                ? $calendar->affectedByInactiveEmployees($user)
                : $calendar->forRange($from, $to, $user, [
                    'branch' => $this->branchUuid ?: null,
                    'employee' => $this->employeeUuid ?: null,
                    'status' => $this->statusFilter ?: null,
                ]);
        } catch (BookingFailed|AuthorizationException $e) {
            $error = $e->getMessage();
        }

        $viewing = $this->current();

        return view('livewire.center.calendar', [
            'branches' => $branches,
            'employees' => Employee::query()->orderBy('id')->get(),
            'services' => Service::query()->active()->with('variations', 'addons')->get(),
            'appointments' => $appointments->map(
                fn (Appointment $a): array => $presenter->summary($a, $user)
            )->values()->all(),
            // NOT named `viewing`: this component already has a public
            // `$viewing` property holding the uuid, and a public property wins
            // over view data of the same name — so the template would silently
            // receive the string instead of the presented appointment.
            'openAppointment' => $viewing instanceof Appointment
                ? $presenter->detail(
                    $viewing->load(['customer', 'branch', 'items.employee', 'items.addons', 'internalNotes']),
                    $user,
                )
                : null,
            'days' => $this->days(),
            'customers' => $this->customerResults($user),
            'canBook' => $user->hasPermission(Permission::AppointmentCreate),
            'canNote' => $user->hasPermission(Permission::AppointmentNoteManage),
            'error' => $error,
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * @return array{0: string, 1: string}
     */
    private function range(): array
    {
        $date = CarbonImmutable::parse($this->date);

        if ($this->view !== 'week') {
            return [$date->format('Y-m-d'), $date->format('Y-m-d')];
        }

        // Weeks start on Saturday in Iraq. Hardcoding Monday would put the
        // weekend in the middle of the grid for every center in the launch
        // market.
        $start = $date->subDays(($date->dayOfWeek + 1) % 7);

        return [$start->format('Y-m-d'), $start->addDays(6)->format('Y-m-d')];
    }

    /**
     * @return list<string>
     */
    private function days(): array
    {
        [$from, $to] = $this->range();

        $days = [];
        $cursor = CarbonImmutable::parse($from);
        $last = CarbonImmutable::parse($to);

        while ($cursor <= $last) {
            $days[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    private function line(): BookingLine
    {
        return new BookingLine(
            serviceUuid: $this->serviceUuid,
            variationUuid: $this->variationUuid === '' ? null : $this->variationUuid,
            addonUuids: $this->addonUuids,
            employeeUuid: $this->bookingEmployeeUuid === '' ? null : $this->bookingEmployeeUuid,
        );
    }

    /**
     * @throws BookingFailed
     */
    private function customerRef(): CustomerRef
    {
        if ($this->customerUuid !== '') {
            return CustomerRef::existing($this->customerUuid);
        }

        if (trim($this->newCustomerName) === '' || trim($this->newCustomerPhone) === '') {
            throw BookingFailed::policy(__('Choose a customer, or enter a name and phone number.'));
        }

        return CustomerRef::details($this->newCustomerName, $this->newCustomerPhone);
    }

    /**
     * Slots for the appointment currently being rescheduled.
     *
     * Rebuilt from the STORED items, so a service whose duration changed since
     * the booking still offers slots that match what was agreed (§3).
     */
    private function slotsForExisting(Appointment $appointment, AvailabilityEngine $engine): void
    {
        $lines = $appointment->items()->with('service')->get()
            ->map(fn ($item): ?BookingLine => $item->service === null ? null : new BookingLine(
                serviceUuid: $item->service->uuid,
                variationUuid: null,
                addonUuids: [],
                employeeUuid: $item->wasCustomerChoice() ? $item->employee?->uuid : null,
            ))
            ->filter()
            ->values()
            ->all();

        if ($lines === []) {
            $this->error = __('That appointment cannot be moved automatically.');

            return;
        }

        try {
            $found = $engine->slots(
                new AvailabilityQuery(
                    branchUuid: $this->branchUuid,
                    lines: $lines,
                    fromDate: $this->bookingDate,
                    toDate: $this->bookingDate,
                ),
                publicChannel: false,
            );
        } catch (BookingFailed $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->slots = array_map(
            static fn (AvailabilitySlot $slot): array => [
                'starts_at' => $slot->startsAt->toIso8601String(),
                'time' => $slot->localTime,
            ],
            $found,
        );
    }

    /**
     * Customer search results — through the CRM query, so masking and the
     * contact-search permission apply exactly as they do in the CRM screen
     * (ADR-042).
     *
     * @return list<array{uuid: string, name: string}>
     */
    private function customerResults(User $user): array
    {
        if (! $this->booking || trim($this->customerSearch) === '') {
            return [];
        }

        if (! $user->hasPermission(Permission::CustomerView)) {
            return [];
        }

        $page = app(CustomerQuery::class)->paginate(['search' => $this->customerSearch], $user, 8);

        return array_map(
            static fn (Customer $c): array => ['uuid' => $c->uuid, 'name' => $c->name],
            $page->items(),
        );
    }

    private function transition(BookingEngine $engine, AppointmentStatus $target, string $notice): void
    {
        $appointment = $this->current();

        if (! $appointment instanceof Appointment) {
            return;
        }

        try {
            $engine->transition($appointment, $target, BookingActor::staff($this->user()));
        } catch (BookingFailed|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = $notice;
        $this->error = '';
    }

    private function current(): ?Appointment
    {
        if ($this->viewing === null) {
            return null;
        }

        return Appointment::query()->where('uuid', $this->viewing)->first();
    }

    private function resetBookingForm(): void
    {
        $this->customerSearch = '';
        $this->customerUuid = '';
        $this->newCustomerName = '';
        $this->newCustomerPhone = '';
        $this->serviceUuid = '';
        $this->variationUuid = '';
        $this->addonUuids = [];
        $this->bookingEmployeeUuid = '';
        $this->bookingNote = '';
        $this->slots = [];
        $this->error = '';
    }

    private function user(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new AuthorizationException('Authentication is required.');
        }

        return $user;
    }
}
