<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;

/**
 * What a visit looks like in the audit trail.
 *
 * ONE SHAPE, for the same reason `AppointmentSnapshot` is one shape: a stage
 * reassignment's `before` and `after` have to be directly comparable, and no
 * Action should have to remember what to leave out
 * (docs/08-AUDIT-SECURITY.md §3).
 *
 * NO CUSTOMER PII and NO NOTE BODIES. The customer's name reaches the audit row
 * once, as the entry's target label — the deliberate Phase 5 exception — and
 * everything here is operational: who did what, when, and where they were
 * standing (ADR-042, docs/13-ROADMAP.md Phase 7 §41).
 *
 * @internal to the ServiceJourney Actions.
 */
final class JourneySnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function of(ServiceJourney $journey): array
    {
        $stages = $journey->relationLoaded('stages')
            ? $journey->stages
            : $journey->stages()->get();

        return [
            'status' => $journey->status->value,
            'arrived_at' => $journey->arrived_at?->toIso8601String(),
            'started_at' => $journey->started_at?->toIso8601String(),
            'completed_at' => $journey->completed_at?->toIso8601String(),
            'aborted_at' => $journey->aborted_at?->toIso8601String(),
            'stages' => $stages->map(static fn (JourneyStage $stage): array => self::stage($stage))->all(),
        ];
    }

    /**
     * One stage on its own, for the actions that only touch one.
     *
     * Carries the ITEM id as well as the stage's own, because the whole point
     * of the trail here is that the actual employee can differ from the booked
     * one — and an entry that showed only the actual would not show that
     * anything had changed (§18).
     *
     * @return array<string, mixed>
     */
    public static function stage(JourneyStage $stage): array
    {
        return [
            'uuid' => $stage->uuid,
            'position' => $stage->position,
            'appointment_item_id' => $stage->appointment_item_id,
            'department_id' => $stage->department_id,
            'employee_id' => $stage->employee_id,
            'status' => $stage->status->value,
            'waiting_started_at' => $stage->waiting_started_at?->toIso8601String(),
            'service_started_at' => $stage->service_started_at?->toIso8601String(),
            'service_completed_at' => $stage->service_completed_at?->toIso8601String(),
            'skip_reason' => $stage->skip_reason,
        ];
    }
}
