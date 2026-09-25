<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Notes\Models\InternalNote;
use App\Modules\Booking\Application\Actions\ManageAppointmentNotes;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Booking\Domain\Models\AppointmentItemAddon;
use App\Modules\Booking\Domain\Models\ResourceReservation;
use App\Modules\Booking\Domain\VerificationCode;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Employees\Domain\Models\Employee;
use SensitiveParameter;

/**
 * The one shape an appointment is presented in, to any surface.
 *
 * The API, the Livewire calendar and any future mobile client all call this —
 * the same rule Phase 5 established for customers, and for the same reason: a
 * masking decision made in a Blade template has already sent the value to the
 * browser and does nothing for the JSON a mobile app receives (ADR-042).
 *
 * ## Two things it is careful about
 *
 * **Customer contact details go through {@see CustomerPresenter}.** A calendar
 * shows who is coming, and a receptionist needs to phone them; an employee does
 * not. Rather than reimplement that judgement, this presenter delegates it, so
 * a cashier reading the day's book sees exactly the masking they see in the CRM.
 *
 * **Staff notes are filtered by visibility and omitted entirely without the
 * permission.** Omitted, not empty — an empty list would imply there are none.
 *
 * Fields are named explicitly. A deny-list is defeated by the next column
 * somebody adds (docs/08-AUDIT-SECURITY.md §19).
 */
final class AppointmentPresenter
{
    public function __construct(
        private readonly CustomerPresenter $customers,
        private readonly ManageAppointmentNotes $notes,
    ) {}

    /**
     * The calendar shape: enough to draw a block and know who it is.
     *
     * @return array<string, mixed>
     */
    public function summary(Appointment $appointment, ?User $viewer): array
    {
        return [
            'uuid' => $appointment->uuid,
            // What a person calls this booking out loud. Public and quotable;
            // it authenticates nothing (docs/24-BOOKING-VERIFICATION.md §1).
            'reference' => $appointment->reference,
            'status' => $appointment->status->value,
            'source' => $appointment->source->value,

            // ISO-8601 with offset for machines, branch-local wall clock for
            // people — a calendar should not have to convert, and a client that
            // guesses the timezone gets it wrong for a two-timezone center
            // (docs/10-API-FOUNDATION.md §8).
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'timezone' => $appointment->booked_timezone,
            'local_date' => $appointment->localDate(),
            'local_start' => $appointment->localStart()->format('H:i'),
            'local_end' => $appointment->localEnd()->format('H:i'),
            'duration_minutes' => $appointment->window()->durationMinutes(),

            'branch' => [
                'uuid' => $appointment->relationLoaded('branch') ? $appointment->branch?->uuid : null,
                'name' => $appointment->relationLoaded('branch') ? $appointment->branch?->name->get() : null,
            ],

            'customer' => $appointment->relationLoaded('customer') && $appointment->customer !== null
                ? $this->customers->summary($appointment->customer, $viewer)
                : null,

            'items' => $this->items($appointment),
            'total' => $this->total($appointment),
        ];
    }

    /**
     * The detail shape: the summary, plus what the customer said and what staff
     * wrote.
     *
     * @return array<string, mixed>
     */
    public function detail(Appointment $appointment, ?User $viewer): array
    {
        return array_merge($this->summary($appointment, $viewer), [
            // The CUSTOMER's own note. Distinct from the staff notes below, and
            // safe to show them (§17).
            'customer_note' => $appointment->customer_note,

            'confirmed_at' => $appointment->confirmed_at?->toIso8601String(),
            'completed_at' => $appointment->completed_at?->toIso8601String(),
            'no_show_at' => $appointment->no_show_at?->toIso8601String(),

            'cancellation' => $appointment->cancelled_at === null ? null : [
                'at' => $appointment->cancelled_at->toIso8601String(),
                'from_status' => $appointment->cancelled_from_status,
                'reason' => $appointment->cancellation_reason,
                'by' => $appointment->cancelled_by_label,
            ],

            'created_by' => [
                'type' => $appointment->created_by_type,
                'label' => $appointment->created_by_label,
            ],

            'notes' => $this->staffNotes($appointment, $viewer),
        ]);
    }

    /**
     * What a CUSTOMER sees of their own appointment.
     *
     * A separate method rather than the staff shape with fields removed. The
     * two audiences are different enough that a shared method with flags would
     * eventually leak one into the other — and the thing that would leak is a
     * staff note about the person reading it.
     *
     * @return array<string, mixed>
     */
    public function forCustomer(Appointment $appointment): array
    {
        return [
            'uuid' => $appointment->uuid,
            'reference' => $appointment->reference,
            'status' => $appointment->status->value,
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'timezone' => $appointment->booked_timezone,
            'local_date' => $appointment->localDate(),
            'local_start' => $appointment->localStart()->format('H:i'),
            'branch' => $appointment->relationLoaded('branch') && $appointment->branch !== null
                ? ['uuid' => $appointment->branch->uuid, 'name' => $appointment->branch->name->get()]
                : null,
            'customer_note' => $appointment->customer_note,
            'items' => $this->items($appointment, forCustomer: true),
            'total' => $this->total($appointment),
            /*
             * Deliberately absent: staff notes, the internal source code, who
             * created the record, cancellation actor, and the employee's
             * status. A customer's view of their own booking is what was
             * agreed, not the center's operational record of it.
             */
        ];
    }

    /**
     * A booking shape plus the one-time verification code, when there is one to
     * show.
     *
     * The ONLY place a raw code reaches a response body. It is added by the
     * call that minted it and by nothing else: a later read of the same
     * appointment produces the same shape WITHOUT the key, because the raw code
     * no longer exists anywhere to produce (docs/24-BOOKING-VERIFICATION.md §11).
     *
     * Absent rather than null when there is nothing to show, so a client cannot
     * read "the field was there and empty" as "this booking has no code".
     *
     * @param  array<string, mixed>  $shape
     * @return array<string, mixed>
     */
    public function withVerificationCode(array $shape, #[SensitiveParameter] ?string $raw): array
    {
        if ($raw === null) {
            return $shape;
        }

        // Grouped for reading aloud. The digest was taken over the normalised
        // form, so the hyphen here changes nothing about verification.
        $shape['verification_code'] = VerificationCode::format($raw);

        return $shape;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(Appointment $appointment, bool $forCustomer = false): array
    {
        $items = $appointment->relationLoaded('items') ? $appointment->items : $appointment->items()->get();

        return $items->map(function (AppointmentItem $item) use ($forCustomer): array {
            $employee = $item->relationLoaded('employee') ? $item->employee : null;

            $shape = [
                'uuid' => $item->uuid,
                'position' => $item->position,
                // The SNAPSHOT name, not the live service's. What the customer
                // booked is what they are shown, even if the catalog was
                // renamed since (§3).
                'service' => $item->service_name->get(),
                'variation' => $item->variation_name?->get(),
                'addons' => $this->addons($item),
                'starts_at' => $item->starts_at->toIso8601String(),
                'duration_minutes' => $item->duration_minutes,
                'price' => $item->price()->toArray(),
                'employee' => $employee instanceof Employee
                    ? ['uuid' => $employee->uuid, 'name' => $employee->name->get()]
                    : null,
            ];

            if (! $forCustomer) {
                $shape['employee_selection'] = $item->employee_selection->value;
                $shape['note'] = $item->customer_note;

                // Whether the booked person still works here — the calendar's
                // "needs a new team member" answer, per service (§17).
                if ($employee instanceof Employee) {
                    $shape['employee']['active'] = $employee->status->isActive();
                }

                // Reserved rooms and devices, only when the caller loaded them:
                // the snapshot NAME, as with the service (§3), and the uuid so a
                // room view can place the booking.
                if ($item->relationLoaded('resourceReservations')) {
                    $shape['resources'] = $item->resourceReservations
                        ->map(static fn (ResourceReservation $reservation): array => [
                            'uuid' => $reservation->relationLoaded('resource') ? $reservation->resource?->uuid : null,
                            'name' => $reservation->resource_name->get(),
                            'type' => $reservation->resource_type_name->get(),
                            'quantity' => $reservation->quantity,
                        ])->values()->all();
                }
            }

            return $shape;
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function addons(AppointmentItem $item): array
    {
        if (! $item->relationLoaded('addons')) {
            return [];
        }

        return $item->addons->map(fn (AppointmentItemAddon $addon): array => [
            'name' => $addon->name->get(),
            'price' => $addon->price()->toArray(),
            'duration_minutes' => $addon->duration_minutes,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function total(Appointment $appointment): array
    {
        $currency = Currency::tryFrom((string) $appointment->currencyCode()) ?? Currency::default();

        return Money::fromMinor($appointment->totalMinor(), $currency)->toArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function staffNotes(Appointment $appointment, ?User $viewer): array
    {
        return array_map(
            static fn (InternalNote $note): array => [
                'uuid' => $note->uuid,
                'body' => $note->body,
                'visibility' => $note->visibility->value,
                'created_at' => $note->created_at?->toIso8601String(),
                // A staff name, never a customer detail; only when loaded.
                'author' => $note->relationLoaded('author') ? $note->author?->name : null,
            ],
            $this->notes->visibleTo($appointment, $viewer),
        );
    }
}
