<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Booking\Domain\Models\Appointment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Staff notes on a booking.
 *
 * THREE KINDS OF NOTE NOW EXIST AND THEY ARE NOT THE SAME THING:
 *
 *   `appointments.customer_note`       written BY the customer, visible to them
 *   `appointment_items.customer_note`  the same, about one service
 *   these                              written by staff, ABOUT the booking,
 *                                      never shown to the customer
 *
 * Conflating them is the mistake worth guarding against: a customer-facing
 * confirmation that rendered the wrong one would show "difficult, do not take
 * her booking again" to the person it is about. The customer notes are columns
 * on the appointment, deliberately not rows in this table, so no surface can
 * reach them by loading "the notes" (docs/13-ROADMAP.md Phase 6 §17).
 *
 * Uses the Phase 4 notes seam unchanged — one new {@see NoteOwner} case, no new
 * table. The remaining owners (order items, journey stages, invoices) arrive
 * the same way.
 *
 * THE NOTE BODY IS NEVER AUDITED, for the reason Phase 5 established: copying
 * free text into a second table with different readers and different retention
 * duplicates whatever sensitive thing it says.
 */
final class ManageAppointmentNotes
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function add(
        Appointment $appointment,
        string $body,
        User $actingUser,
        NoteVisibility $visibility = NoteVisibility::Internal,
    ): InternalNote {
        $this->authorize($appointment, $actingUser, Permission::AppointmentNoteManage);

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'A note needs some text.']);
        }

        $note = $appointment->addInternalNote($body, $actingUser, $visibility);

        $this->audit->record(new AuditEvent(
            action: 'booking.appointment_note.created',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $appointment->customer()->value('name'),
            meta: [
                'note_uuid' => $note->uuid,
                'visibility' => $visibility->value,
                // Length, not content — enough to tell an accidental empty save
                // from a real note.
                'length' => mb_strlen($body),
            ],
        ));

        return $note;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(Appointment $appointment, InternalNote $note, User $actingUser): void
    {
        $this->authorize($appointment, $actingUser, Permission::AppointmentNoteManage);

        // Scoped to this appointment, so a note uuid belonging to another
        // record — or to a CUSTOMER — cannot be deleted through this endpoint.
        if ($note->owner_id !== $appointment->id || $note->owner_type !== NoteOwner::Appointment) {
            throw new AuthorizationException('That note does not belong to this appointment.');
        }

        $uuid = $note->uuid;

        $note->delete();

        $this->audit->record(new AuditEvent(
            action: 'booking.appointment_note.deleted',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Appointment::class,
            targetId: $appointment->uuid,
            targetLabel: $appointment->customer()->value('name'),
            meta: ['note_uuid' => $uuid],
        ));
    }

    /**
     * The notes this viewer may read, filtered by visibility.
     *
     * @param  iterable<InternalNote>|null  $loaded  already-loaded notes, to
     *                                               avoid a query per row in a
     *                                               calendar
     * @return list<InternalNote>
     */
    public function visibleTo(Appointment $appointment, ?User $viewer, ?iterable $loaded = null): array
    {
        if ($viewer === null || ! $viewer->hasPermission(Permission::AppointmentNoteView)) {
            return [];
        }

        $notes = $loaded ?? ($appointment->relationLoaded('internalNotes')
            ? $appointment->internalNotes
            : $appointment->internalNotes()->get());

        $visible = [];

        foreach ($notes as $note) {
            if ($viewer->hasPermission($note->visibility->requiredPermission(NoteOwner::Appointment))) {
                $visible[] = $note;
            }
        }

        return $visible;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Appointment $appointment, User $user, Permission $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException('You may not write booking notes.');
        }

        // Permission plus branch scope, as everywhere (docs/06 §5).
        if (! $user->canAccessBranch((int) $appointment->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
