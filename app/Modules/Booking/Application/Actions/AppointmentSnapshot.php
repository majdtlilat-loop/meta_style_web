<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application\Actions;

use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;

/**
 * What an appointment looks like in the audit trail.
 *
 * ONE SHAPE, so a reschedule's `before` and `after` are directly comparable and
 * so no Action has to remember what to leave out. `before`/`after` hold changed
 * attributes, not whole rows (docs/08-AUDIT-SECURITY.md §3).
 *
 * NO CUSTOMER PII. No phone, no email, no name — the name appears once, as the
 * entry's target label, which is the deliberate exception Phase 5 made so an
 * investigation has something readable to work with. Everything here is
 * schedule and money (ADR-042, docs/13-ROADMAP.md Phase 6 §32).
 *
 * The item lines are what make a rescheduling entry useful: "moved from 10:00
 * to 11:00" is only half the story when the second stylist changed too.
 *
 * @internal to the Booking Actions.
 */
final class AppointmentSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Appointment $appointment): array
    {
        $items = $appointment->relationLoaded('items')
            ? $appointment->items
            : $appointment->items()->get();

        return [
            'status' => $appointment->status->value,
            // ISO-8601 with offset, and the branch-local wall clock beside it —
            // an auditor reading "17:00+03:00" should not have to convert.
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'ends_at' => $appointment->ends_at->toIso8601String(),
            'timezone' => $appointment->booked_timezone,
            'local_start' => $appointment->localStart()->format('Y-m-d H:i'),
            'branch_id' => $appointment->branch_id,
            'items' => $items->map(static fn (AppointmentItem $item): array => [
                'uuid' => $item->uuid,
                'position' => $item->position,
                'service_id' => $item->service_id,
                'variation_id' => $item->service_variation_id,
                'employee_id' => $item->employee_id,
                'selection' => $item->employee_selection->value,
                'starts_at' => $item->starts_at->toIso8601String(),
                'duration_minutes' => $item->duration_minutes,
                // The snapshot as booked. If a later price change ever DID
                // reach an existing appointment, the audit trail is where it
                // would be visible.
                'price_minor' => $item->price_minor,
                'currency' => $item->currency,
            ])->values()->all(),
        ];
    }
}
