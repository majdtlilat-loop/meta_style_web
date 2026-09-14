<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteOwner;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\ServiceJourney\Application\Actions\ManageStageNotes;
use App\Modules\ServiceJourney\Domain\Models\JourneyHandoff;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;

/**
 * Turns a visit into the JSON a staff client renders.
 *
 * ## An allow-list, always
 *
 * Every field is named. Never `$model->toArray()` minus a deny-list: the next
 * column somebody adds would be published by default, and on this module that
 * would be an internal note or an abort reason
 * (docs/08-AUDIT-SECURITY.md, docs/13-ROADMAP.md Phase 7 §37).
 *
 * ## Staff only
 *
 * There is no customer-facing journey surface in Phase 7 and no route that
 * would reach one. A customer does not need to know which room they are in,
 * who was reassigned, or why a device was swapped (§46).
 *
 * ## Planned beside actual
 *
 * Each stage carries BOTH the booked employee and the one who actually
 * performed it. Publishing only the actual would hide the divergence the whole
 * module exists to record — a host looking at the board should be able to see
 * that the customer is not with the stylist they asked for (§18).
 */
final class JourneyPresenter
{
    public function __construct(private readonly ManageStageNotes $notes) {}

    /**
     * One board row: the booking, and what is happening to it.
     *
     * @return array<string, mixed>
     */
    public function row(BoardRow $row, User $viewer): array
    {
        $appointment = $row->appointment;

        return [
            'group' => $row->group(),
            // `walk_in` or `appointment` — a client should not have to infer
            // which kind of visit this is from a null.
            'source' => $row->isWalkIn() ? 'walk_in' : 'appointment',
            'customer_name' => $row->customer()?->name,
            /*
             * NULL FOR A WALK-IN, and that is the whole point: there was no
             * reservation, so there is no planned start to report. Inventing one
             * would make the board's "late by" column lie about somebody who was
             * never expected (docs/16-JOURNEY-RESOURCES.md §22).
             */
            'appointment' => $appointment === null ? null : [
                'uuid' => $appointment->uuid,
                'status' => $appointment->status->value,
                'starts_at' => $appointment->starts_at->toIso8601String(),
                'ends_at' => $appointment->ends_at->toIso8601String(),
                'local_start' => $appointment->localStart()->format('H:i'),
                'customer_name' => $appointment->customer?->name,
            ],
            'journey' => $row->journey === null ? null : $this->journey($row->journey, $viewer),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function journey(ServiceJourney $journey, User $viewer): array
    {
        return [
            'uuid' => $journey->uuid,
            'status' => $journey->status->value,
            'arrived_at' => $journey->arrived_at?->toIso8601String(),
            'started_at' => $journey->started_at?->toIso8601String(),
            'completed_at' => $journey->completed_at?->toIso8601String(),
            'aborted_at' => $journey->aborted_at?->toIso8601String(),
            // Staff-facing and operational. Never a customer surface.
            'abort_reason' => $journey->aborted_at === null ? null : $journey->abort_reason,
            'stages' => $journey->stages
                ->map(fn (JourneyStage $stage): array => $this->stage($stage, $viewer))
                ->all(),
            'handoffs' => $journey->relationLoaded('handoffs')
                ? $journey->handoffs->map(fn (JourneyHandoff $h): array => $this->handoff($h))->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function stage(JourneyStage $stage, User $viewer): array
    {
        $item = $stage->item;

        return [
            'uuid' => $stage->uuid,
            'position' => $stage->position,
            'status' => $stage->status->value,
            'department' => $stage->department === null ? null : [
                'uuid' => $stage->department->uuid,
                'name' => $stage->department->name->get(),
            ],

            /*
             * PLANNED vs ACTUAL, side by side. The booked employee comes from
             * the appointment item and never changes; `employee` is who is
             * actually doing it (§§18, 27).
             */
            'employee' => $stage->employee === null ? null : [
                'uuid' => $stage->employee->uuid,
                'name' => $stage->employee->name->get(),
            ],
            'booked_employee_uuid' => $item instanceof AppointmentItem
                ? $item->employee?->uuid
                : null,

            // Booked stages read the item's snapshot; a walk-in stage carries
            // its own, taken when reception chose the service.
            'service_name' => $stage->serviceName()?->get(),
            'planned_starts_at' => $item instanceof AppointmentItem
                ? $item->starts_at->toIso8601String()
                : null,
            'planned_duration_minutes' => $item?->duration_minutes,
            'expected_duration_minutes' => $stage->durationMinutes(),

            'waiting_started_at' => $stage->waiting_started_at?->toIso8601String(),
            'service_started_at' => $stage->service_started_at?->toIso8601String(),
            'service_completed_at' => $stage->service_completed_at?->toIso8601String(),
            'skip_reason' => $stage->skip_reason,

            'resources' => $stage->relationLoaded('resources')
                ? $stage->resources->map(fn (JourneyStageResource $r): array => $this->usage($r))->all()
                : [],

            'notes' => $this->stageNotes($stage, $viewer),
        ];
    }

    /**
     * Actual resource usage, INTERVALS included.
     *
     * `released_at` is what makes a swap readable afterwards — "Laser 1 until
     * 10:15, Laser 2 from 10:15" rather than "both were involved somehow"
     * (Phase 7 corrections §5).
     *
     * @return array<string, mixed>
     */
    private function usage(JourneyStageResource $usage): array
    {
        return [
            'resource' => $usage->resource === null ? null : [
                'uuid' => $usage->resource->uuid,
                'name' => $usage->resource->name->get(),
            ],
            'quantity' => $usage->quantity,
            'assigned_at' => $usage->assigned_at->toIso8601String(),
            'released_at' => $usage->released_at?->toIso8601String(),
            'release_reason' => $usage->release_reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handoff(JourneyHandoff $handoff): array
    {
        return [
            'uuid' => $handoff->uuid,
            'at' => $handoff->created_at?->toIso8601String(),
            'from_stage_uuid' => $handoff->fromStage?->uuid,
            'to_stage_uuid' => $handoff->toStage?->uuid,
            'from_employee_uuid' => $handoff->fromEmployee?->uuid,
            'to_employee_uuid' => $handoff->toEmployee?->uuid,
            'from_department_uuid' => $handoff->fromDepartment?->uuid,
            'to_department_uuid' => $handoff->toDepartment?->uuid,
            'note' => $handoff->note,
            'by' => $handoff->actor_label,
        ];
    }

    /**
     * Notes this viewer may read.
     *
     * Filtered by VISIBILITY, not merely by whether the endpoint returns them:
     * a manager-only note must not reach a stylist's tablet just because the
     * stage did (§29).
     *
     * @return list<array<string, mixed>>
     */
    private function stageNotes(JourneyStage $stage, User $viewer): array
    {
        if (! $viewer->hasPermission(Permission::JourneyNoteView)) {
            return [];
        }

        $visible = $this->notes->visibleTo($stage, $viewer);

        return array_map(static fn (InternalNote $note): array => [
            'uuid' => $note->uuid,
            'body' => $note->body,
            'visibility' => $note->visibility->value,
            'author' => $note->author?->name,
            'created_at' => $note->created_at?->toIso8601String(),
        ], $visible);
    }

    /**
     * The owner case these notes belong to, for callers that need it.
     */
    public function noteOwner(): NoteOwner
    {
        return NoteOwner::JourneyStage;
    }
}
