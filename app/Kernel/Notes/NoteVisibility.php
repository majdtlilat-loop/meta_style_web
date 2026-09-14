<?php

declare(strict_types=1);

namespace App\Kernel\Notes;

use App\Kernel\Authorization\Permission;

/**
 * Who, among staff, may read a note.
 *
 * Two levels, because two have a real consumer today. `internal` is the
 * everyday case; `manager_only` exists because a note like "disputed a charge
 * and was abusive to the stylist" is one a manager needs and one that must not
 * be on the screen reception turns toward the customer.
 *
 * `customer_visible` is deliberately absent. It is the interesting future case
 * and it needs things that do not exist: a customer-facing surface to appear
 * on, an authoring flow that makes the audience obvious to whoever is typing,
 * and a decision about editing a note the customer has already read. Adding the
 * case now would be guesswork with a database column attached
 * (docs/13-ROADMAP.md Phase 5 §11).
 */
enum NoteVisibility: string
{
    /** Any member of staff who may read this customer's notes. */
    case Internal = 'internal';

    /** Restricted to staff who may also manage notes. */
    case ManagerOnly = 'manager_only';

    /**
     * The permission required to READ a note at this level, on this owner.
     *
     * The owner is a parameter rather than assumed, because reading notes on a
     * customer and reading notes on an appointment are different grants — a
     * receptionist needs booking notes on every shift and has no business in a
     * customer's manager-only history. Hard-coding the customer permissions
     * here would have silently given the two the same audience the moment
     * appointments arrived (docs/13-ROADMAP.md Phase 6 §§17, 30).
     */
    public function requiredPermission(NoteOwner $owner): Permission
    {
        return match ($owner) {
            NoteOwner::Appointment => match ($this) {
                self::Internal => Permission::AppointmentNoteView,
                self::ManagerOnly => Permission::AppointmentNoteManage,
            },

            // Journey notes are a floor conversation, not a booking one. A
            // stylist reads and writes them all shift and has no business in
            // the booking desk's notes about the same customer.
            NoteOwner::JourneyStage => match ($this) {
                self::Internal => Permission::JourneyNoteView,
                self::ManagerOnly => Permission::JourneyNoteManage,
            },

            // Customers, and the Phase 4 catalog owners, which have only ever
            // carried `internal` notes.
            NoteOwner::Customer, NoteOwner::Service, NoteOwner::Department => match ($this) {
                self::Internal => Permission::CustomerNoteView,
                self::ManagerOnly => Permission::CustomerNoteManage,
            },
        };
    }
}
