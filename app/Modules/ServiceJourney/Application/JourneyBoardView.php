<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteVisibility;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\ServiceJourney\Application\Actions\ManageStageNotes;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyHandoff;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\JourneyStageResource;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The Manager's visit board, shaped for a screen: cards, lanes, the detail
 * panel, and WHICH BUTTONS each viewer gets.
 *
 * ## Why the buttons are decided here
 *
 * "May this person finish this stage right now?" is the stage state machine,
 * the permission map `TransitionStage` uses and the booking entitlement. A
 * template that re-derived it would be a second, quieter copy of those rules.
 * The flags below are PRESENTATION — the Actions still refuse on the server
 * whatever a client sends.
 *
 * ## Branch-local wall clock
 *
 * Every time shown is converted once, here, in the visit's branch timezone
 * (`BranchClock`). A board open in Baghdad showing an Istanbul branch reads
 * Istanbul's clock, which is what the people standing there see.
 *
 * ## Light on purpose
 *
 * `cards()` runs on every poll and touches no notes and no handoffs — those
 * are the detail panel's (`panel()`), for one visit at a time. The board is
 * one query per source (JourneyBoardQuery) plus the branch clock lookup.
 */
final class JourneyBoardView
{
    /** @var array<int, string>|null */
    private ?array $timezones = null;

    public function __construct(private readonly ManageStageNotes $notes) {}

    /**
     * @param  list<BoardRow>  $rows
     * @return list<array<string, mixed>>
     */
    public function cards(array $rows, User $viewer, bool $entitled, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $flags = $this->permissions($viewer, $entitled);

        return array_map(fn (BoardRow $row): array => $this->card($row, $flags, $now), $rows);
    }

    /**
     * The five lanes, in board order, each with its cards.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, list<array<string, mixed>>>
     */
    public function lanes(array $cards): array
    {
        $lanes = ['not_arrived' => [], 'waiting' => [], 'in_service' => [], 'completed' => [], 'abandoned' => []];

        foreach ($cards as $card) {
            $lanes[(string) $card['group']][] = $card;
        }

        return $lanes;
    }

    /**
     * The whole visit, for the drawer.
     *
     * @return array<string, mixed>
     */
    public function panel(BoardRow $row, User $viewer, bool $entitled, bool $queueEnabled, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $flags = $this->permissions($viewer, $entitled);
        $journey = $row->journey;
        $timezone = $this->timezone($row->branchId());
        $card = $this->card($row, $flags, $now);

        if (! $journey instanceof ServiceJourney) {
            return $card + ['stages' => [], 'handoffs' => [], 'panel' => null];
        }

        $active = $journey->status === JourneyStatus::Active;
        $settled = $journey->stages->every(static fn (JourneyStage $s): bool => $s->isTerminal());

        $stages = $journey->stages->map(function (JourneyStage $stage) use ($flags, $active, $timezone, $viewer, $now, $queueEnabled): array {
            $item = $stage->item;
            $waiting = $stage->status === StageStatus::Waiting;
            $inService = $stage->status === StageStatus::InService;

            return [
                'uuid' => $stage->uuid,
                'position' => $stage->position,
                'status' => $stage->status->value,
                'service' => (string) ($stage->serviceName()?->get() ?? ''),
                'department' => $stage->department?->name->get(),
                'employee' => $stage->employee?->name->get(),
                'booked_employee' => $item instanceof AppointmentItem ? $item->employee?->name->get() : null,
                'reassigned' => $item instanceof AppointmentItem
                    && $item->employee_id !== null
                    && $stage->employee_id !== null
                    && (int) $item->employee_id !== (int) $stage->employee_id,
                'planned' => $item instanceof AppointmentItem ? $this->clock($item->starts_at, $timezone) : null,
                'started' => $this->clock($stage->service_started_at, $timezone),
                'finished' => $this->clock($stage->service_completed_at, $timezone),
                'elapsed' => $inService && $stage->service_started_at !== null
                    ? $this->minutesBetween($stage->service_started_at, $now)
                    : null,
                'expected' => $stage->durationMinutes(),
                'skip_reason' => $stage->skip_reason,
                'resources' => $stage->resources->map(fn (JourneyStageResource $usage): array => [
                    'name' => (string) ($usage->resource?->name->get() ?? ''),
                    'from' => $this->clock($usage->assigned_at, $timezone),
                    'until' => $this->clock($usage->released_at, $timezone),
                    'open' => $usage->released_at === null,
                ])->values()->all(),
                'notes' => $this->notes($stage, $viewer, $timezone),
                'can' => [
                    'start' => $active && $waiting && $flags['start'],
                    'finish' => $active && $inService && $flags['complete'],
                    'skip' => $active && $waiting && $flags['complete'],
                    'handoff' => $active && $inService && $flags['reassign'] && $flags['complete'],
                    'reassign' => $active && ! $stage->isTerminal() && $flags['reassign'],
                    'swap' => $active && $inService && $flags['reassign'],
                    'note' => $flags['note'],
                    'ticket' => $active && $waiting && $queueEnabled && $flags['queue_issue'],
                ],
            ];
        })->values()->all();

        return $card + [
            'arrived' => $this->clock($journey->arrived_at, $timezone),
            'completed' => $this->clock($journey->completed_at, $timezone),
            'aborted' => $this->clock($journey->aborted_at, $timezone),
            'abort_reason' => $journey->aborted_at === null ? null : $journey->abort_reason,
            'journey_status' => $journey->status->value,
            'stages' => $stages,
            'handoffs' => $journey->relationLoaded('handoffs')
                ? $journey->handoffs->map(fn (JourneyHandoff $handoff): array => [
                    'at' => $this->clock($handoff->created_at, $timezone),
                    'from' => $handoff->fromStage?->serviceName()?->get(),
                    'to' => $handoff->toStage?->serviceName()?->get(),
                    'from_employee' => $handoff->fromEmployee?->name->get(),
                    'to_employee' => $handoff->toEmployee?->name->get(),
                    'note' => $handoff->note,
                    'by' => $handoff->actor_label,
                ])->values()->all()
                : [],
            'panel' => [
                'complete' => $active && $settled && $flags['manage']
                    && ($row->isWalkIn() || $flags['appointment_complete']),
                'leave' => $active && $flags['manage'],
                'cancel_booking' => $active && ! $row->isWalkIn() && $flags['manage'] && $flags['appointment_cancel'],
                // Writing either kind needs `journey.note.manage`, which is
                // also what reading a manager-only note needs.
                'note_visibility' => [NoteVisibility::Internal->value, NoteVisibility::ManagerOnly->value],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, int>
     */
    public function counts(array $cards): array
    {
        $counts = ['total' => count($cards), 'not_arrived' => 0, 'waiting' => 0, 'in_service' => 0, 'completed' => 0, 'abandoned' => 0, 'walk_ins' => 0, 'waiting_walk_ins' => 0, 'late' => 0];

        foreach ($cards as $card) {
            $counts[(string) $card['group']]++;
            $counts['walk_ins'] += $card['source'] === 'walk_in' ? 1 : 0;
            // Shown under "Waiting": only the walk-ins that are waiting now.
            $counts['waiting_walk_ins'] += $card['source'] === 'walk_in' && $card['group'] === 'waiting' ? 1 : 0;
            $counts['late'] += ($card['late_minutes'] ?? 0) > 0 ? 1 : 0;
        }

        return $counts;
    }

    /**
     * @param  array<string, bool>  $flags
     * @return array<string, mixed>
     */
    private function card(BoardRow $row, array $flags, CarbonImmutable $now): array
    {
        $appointment = $row->appointment;
        $journey = $row->journey;
        $timezone = $this->timezone($row->branchId());
        $group = $row->group();
        $active = $journey?->status === JourneyStatus::Active;

        $stages = $journey?->stages->all() ?? [];
        $current = $row->currentStage();
        $next = null;

        foreach ($stages as $stage) {
            if ($stage->status === StageStatus::Waiting) {
                $next = $stage;

                break;
            }
        }

        $services = $journey instanceof ServiceJourney
            ? array_map(static fn (JourneyStage $s): string => (string) ($s->serviceName()?->get() ?? ''), $stages)
            : ($appointment === null ? [] : $appointment->items->map(static fn (AppointmentItem $i): string => $i->service_name->get())->all());

        $late = null;

        if ($group === 'not_arrived' && $appointment !== null && $appointment->starts_at->utc()->lessThan($now)) {
            $late = $this->minutesBetween($appointment->starts_at, $now);
        }

        $waitingSince = null;

        if ($group === 'waiting' && $journey?->arrived_at !== null) {
            // The last thing that happened: arrival, or the previous service
            // finishing. A customer between two services is waiting again.
            $last = $journey->arrived_at;

            foreach ($stages as $stage) {
                if ($stage->service_completed_at !== null && $stage->service_completed_at->greaterThan($last)) {
                    $last = $stage->service_completed_at;
                }
            }

            $waitingSince = $this->minutesBetween($last, $now);
        }

        $settled = count(array_filter($stages, static fn (JourneyStage $s): bool => $s->isTerminal()));

        return [
            'key' => $journey->uuid ?? $appointment?->uuid,
            'group' => $group,
            'source' => $row->isWalkIn() ? 'walk_in' : 'appointment',
            'customer' => $row->customer()?->name,
            'reference' => $appointment?->reference,
            'appointment_uuid' => $appointment?->uuid,
            'appointment_status' => $appointment?->status->value,
            'journey_uuid' => $journey?->uuid,
            'time' => $appointment !== null
                ? $this->clock($appointment->starts_at, $timezone)
                : $this->clock($journey?->arrived_at, $timezone),
            'arrived' => $this->clock($journey?->arrived_at, $timezone),
            'late_minutes' => $late,
            'waiting_minutes' => $waitingSince,
            // Presentation tones: amber from a quarter of an hour, red from
            // half an hour (late: red from fifteen minutes).
            'late_tone' => $late === null ? null : ($late >= 15 ? 'danger' : 'warning'),
            'waiting_tone' => $waitingSince === null ? null : ($waitingSince >= 30 ? 'danger' : ($waitingSince >= 15 ? 'warning' : 'neutral')),
            'services' => array_values(array_filter($services, static fn (string $name): bool => $name !== '')),
            'progress' => ['done' => $settled, 'total' => count($stages)],
            'current' => $current === null ? null : $this->current($current, $timezone, $now),
            'next' => $next === null ? null : [
                'uuid' => $next->uuid,
                'service' => (string) ($next->serviceName()?->get() ?? ''),
                'employee' => $next->employee?->name->get() ?? $this->bookedEmployee($next),
            ],
            'can' => [
                'check_in' => $journey === null && $flags['manage'],
                'start' => $active && $current === null && $next !== null && $flags['start'],
                'finish' => $active && $current !== null && $flags['complete'],
                'checkout' => $journey instanceof ServiceJourney && $flags['checkout'],
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(User $viewer, bool $entitled): array
    {
        $can = static fn (Permission $permission): bool => $entitled && $viewer->hasPermission($permission);

        return [
            'manage' => $can(Permission::JourneyManage),
            'start' => $can(Permission::JourneyStageStart),
            'complete' => $can(Permission::JourneyStageComplete),
            'reassign' => $can(Permission::JourneyStageReassign),
            'note' => $viewer->hasPermission(Permission::JourneyNoteManage),
            'appointment_complete' => $viewer->hasPermission(Permission::AppointmentComplete),
            'appointment_cancel' => $viewer->hasPermission(Permission::AppointmentCancel),
            'queue_issue' => $viewer->hasPermission(Permission::QueueManage),
            // Only a flag for a LINK: the till enforces `pos` and `sale.create`
            // itself, and this board never calls Sales (docs/18-SALES.md §33).
            'checkout' => $viewer->hasPermission(Permission::SaleCreate),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notes(JourneyStage $stage, User $viewer, string $timezone): array
    {
        return array_map(fn (InternalNote $note): array => [
            'uuid' => $note->uuid,
            'body' => $note->body,
            'visibility' => $note->visibility->value,
            'author' => $note->author?->name,
            'at' => $this->clock($note->created_at, $timezone),
        ], $this->notes->visibleTo($stage, $viewer, $viewer->hasPermission(Permission::JourneyNoteView)
            // Authors eager-loaded: a stage with several notes is otherwise a
            // query per note (and a lazy-loading violation outside production).
            ? $stage->internalNotes()->with('author')->get()
            : []));
    }

    /**
     * The service in progress, with how far through its expected length it is.
     *
     * @return array<string, mixed>
     */
    private function current(JourneyStage $stage, string $timezone, CarbonImmutable $now): array
    {
        $elapsed = $stage->service_started_at === null ? null : $this->minutesBetween($stage->service_started_at, $now);
        $expected = $stage->durationMinutes();

        return [
            'uuid' => $stage->uuid,
            'service' => (string) ($stage->serviceName()?->get() ?? ''),
            'employee' => $stage->employee?->name->get(),
            'started' => $this->clock($stage->service_started_at, $timezone),
            'elapsed' => $elapsed,
            'expected' => $expected,
            'percent' => $elapsed === null ? 0 : min(100, (int) round($elapsed * 100 / max(1, $expected))),
            'overrun' => $elapsed !== null && $elapsed > $expected,
        ];
    }

    private function bookedEmployee(JourneyStage $stage): ?string
    {
        $item = $stage->item;

        return $item instanceof AppointmentItem ? $item->employee?->name->get() : null;
    }

    private function timezone(int $branchId): string
    {
        $this->timezones ??= Branch::query()->pluck('timezone', 'id')
            ->map(static fn (mixed $zone): string => (string) $zone)
            ->all();

        return $this->timezones[$branchId] ?? 'UTC';
    }

    private function clock(?CarbonInterface $instant, string $timezone): ?string
    {
        if ($instant === null) {
            return null;
        }

        return BranchClock::toLocal(CarbonImmutable::instance($instant)->utc(), $timezone)->format('H:i');
    }

    private function minutesBetween(CarbonInterface $from, CarbonImmutable $to): int
    {
        return max(0, (int) floor((($to->getTimestamp()) - $from->getTimestamp()) / 60));
    }
}
