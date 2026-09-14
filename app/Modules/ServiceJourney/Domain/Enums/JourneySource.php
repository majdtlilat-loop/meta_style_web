<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Enums;

/**
 * Where an operational visit came from.
 *
 * Two cases, and the difference is structural rather than cosmetic:
 *
 *     Appointment   the visit was reserved; the journey reads its customer,
 *                   branch and services through `appointments`
 *     WalkIn        nobody reserved anything; the journey carries the customer
 *                   and branch itself, and each stage carries its own service
 *                   snapshot
 *
 * Stored explicitly rather than inferred from `appointment_id IS NULL`. A
 * reader should be able to tell what kind of visit a row is without knowing the
 * invariant, and a query that filters walk-ins should say so
 * (docs/16-JOURNEY-RESOURCES.md §22).
 *
 * There is no `queue` case. A queue ticket is a waiting mechanism around a
 * stage, not a way a visit begins — a walk-in checked in at reception and a
 * walk-in issued a ticket are the same visit (docs/17-QUEUE.md §1).
 */
enum JourneySource: string
{
    case Appointment = 'appointment';
    case WalkIn = 'walk_in';

    public function isWalkIn(): bool
    {
        return $this === self::WalkIn;
    }

    public function label(): string
    {
        return match ($this) {
            self::Appointment => __('Booked'),
            self::WalkIn => __('Walk-in'),
        };
    }
}
