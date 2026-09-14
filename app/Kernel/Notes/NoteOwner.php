<?php

declare(strict_types=1);

namespace App\Kernel\Notes;

use App\Kernel\Media\MediaOwner;

/**
 * What an internal note is attached to.
 *
 * THE SEAM FOR THE FUTURE NOTES ENGINE. Meta Style's locked requirement is that
 * notes eventually attach to customers, bookings, order items, services,
 * departments, journey stages, staff and invoices. Seven of those nine do not
 * exist yet, so building the engine now would mean designing it against
 * imagined requirements and then rewriting it.
 *
 * Phase 5 added `customer` and Phase 6 `appointment` — one case each, exactly
 * as predicted, with no new table and no migration of existing rows. The
 * remaining owners (order items, journey stages, staff, invoices) arrive the
 * same way, when the things they attach to exist.
 *
 * Stable strings for the same reason as {@see MediaOwner}: a
 * class name in the column would not survive a namespace move.
 */
enum NoteOwner: string
{
    case Service = 'service';
    case Department = 'department';
    case Customer = 'customer';

    /**
     * Staff notes on a booking: "asked for the senior stylist", "running late,
     * called ahead".
     *
     * Distinct from `appointments.customer_note`, which the CUSTOMER wrote and
     * which they can see. These are internal and must never reach a
     * customer-facing surface (docs/13-ROADMAP.md Phase 6 §17).
     */
    case Appointment = 'appointment';

    /**
     * Operational notes on one stage of a visit: "move to room 3", "needs ten
     * minutes before the next service", "asked to be handed to Sara".
     *
     * The third owner to arrive exactly as predicted, with no new table and no
     * migration of existing rows. Internal only — these never reach a customer
     * surface, and they are not a treatment record: the same NoteAdvisory that
     * guards every other note field applies here
     * (docs/13-ROADMAP.md Phase 7 §29).
     */
    case JourneyStage = 'journey_stage';
}
