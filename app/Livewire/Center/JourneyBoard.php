<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\NoteAdvisory;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\ServiceJourney\Application\Actions\AbortJourney;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\HandoffStage;
use App\Modules\ServiceJourney\Application\Actions\ManageStageNotes;
use App\Modules\ServiceJourney\Application\Actions\ReassignStageEmployee;
use App\Modules\ServiceJourney\Application\Actions\SwapStageResource;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Application\BoardRow;
use App\Modules\ServiceJourney\Application\JourneyBoardQuery;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Today's floor.
 *
 * ## Not the queue display
 *
 * A staff working board — who is expected, who is here, who is being served,
 * who is finished. No ticket numbers, no calling, no TV output, no
 * announcements. Phase 8 builds the queue on top of these tables
 * (docs/13-ROADMAP.md Phase 7 §§32, 33, 44).
 *
 * ## Refreshed by asking, not by pushing
 *
 * No WebSockets, no Reverb, no broadcasting. A `wire:poll` and a refresh button
 * are enough for a board a host glances at, and real-time infrastructure would
 * be a production dependency added for a requirement nobody has stated yet
 * (§38).
 *
 * ## Nothing here decides anything
 *
 * Every button calls the Action the API calls. The transitions, the resource
 * holds, the branch scope and the audit entries live there, so the board and
 * the API can never drift apart (docs/04-MODULE-BOUNDARIES.md).
 */
#[Layout('components.layouts.app')]
final class JourneyBoard extends Component
{
    #[Url]
    public string $date = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $employee = '';

    #[Url]
    public string $group = '';

    /** The visit whose detail panel is open. */
    public string $openJourney = '';

    public string $noteBody = '';

    public string $noteStage = '';

    public string $reassignStage = '';

    public string $reassignEmployee = '';

    public string $swapStage = '';

    public string $swapFrom = '';

    public string $swapTo = '';

    public string $abortReason = '';

    public ?string $error = null;

    public ?string $saved = null;

    public function mount(): void
    {
        if (! $this->user()->hasPermission(Permission::JourneyView)
            && ! $this->user()->hasPermission(Permission::JourneyViewOwn)) {
            throw new AuthorizationException('You may not view the visit board.');
        }
    }

    public function checkIn(string $appointmentUuid, CheckInAppointment $checkIn): void
    {
        $this->run(function () use ($appointmentUuid, $checkIn): void {
            $appointment = $this->appointment($appointmentUuid);

            if ($appointment === null) {
                return;
            }

            $journey = $checkIn($appointment, $this->user());

            $this->openJourney = $journey->uuid;
            $this->saved = __('Customer checked in.');
        });
    }

    public function startStage(string $uuid, TransitionStage $transition): void
    {
        $this->transition($uuid, StageStatus::InService, $transition);
    }

    public function completeStage(string $uuid, TransitionStage $transition): void
    {
        $this->transition($uuid, StageStatus::Completed, $transition);
    }

    public function skipStage(string $uuid, string $reason, TransitionStage $transition): void
    {
        $this->transition($uuid, StageStatus::Skipped, $transition, $reason);
    }

    public function reassign(ReassignStageEmployee $reassign): void
    {
        $this->run(function () use ($reassign): void {
            $stage = $this->stage($this->reassignStage);

            if ($stage === null) {
                return;
            }

            $reassign($stage, $this->reassignEmployee, $this->user());

            $this->reset(['reassignStage', 'reassignEmployee']);
            $this->saved = __('Reassigned. The booking still records who was originally booked.');
        });
    }

    public function swapResource(SwapStageResource $swap): void
    {
        $this->run(function () use ($swap): void {
            $stage = $this->stage($this->swapStage);

            if ($stage === null) {
                return;
            }

            $swap($stage, $this->swapFrom, $this->swapTo, $this->user());

            $this->reset(['swapStage', 'swapFrom', 'swapTo']);
            $this->saved = __('Resource swapped. The previous one is kept in the history.');
        });
    }

    public function handoff(string $uuid, HandoffStage $handoff): void
    {
        $this->run(function () use ($uuid, $handoff): void {
            $stage = $this->stage($uuid);

            if ($stage === null) {
                return;
            }

            $handoff($stage, $this->user());

            $this->saved = __('Customer handed on.');
        });
    }

    public function addNote(ManageStageNotes $notes): void
    {
        $this->run(function () use ($notes): void {
            $stage = $this->stage($this->noteStage);

            if ($stage === null) {
                return;
            }

            $notes->add($stage, $this->noteBody, $this->user(), NoteVisibility::Internal);

            $this->reset(['noteStage', 'noteBody']);
            $this->saved = __('Note added.');
        });
    }

    public function completeJourney(string $uuid, CompleteJourney $complete): void
    {
        $this->run(function () use ($uuid, $complete): void {
            $journey = $this->journey($uuid);

            if ($journey === null) {
                return;
            }

            $complete($journey, $this->user());

            $this->openJourney = '';
            $this->saved = __('Visit completed.');
        });
    }

    public function abortJourney(string $uuid, AbortJourney $abort): void
    {
        $this->run(function () use ($uuid, $abort): void {
            $journey = $this->journey($uuid);

            if ($journey === null) {
                return;
            }

            $abort($journey, $this->user(), $this->abortReason === '' ? null : $this->abortReason);

            $this->reset(['abortReason']);
            $this->openJourney = '';
            $this->saved = __('Visit marked as abandoned. The appointment itself was not changed.');
        });
    }

    public function render(JourneyBoardQuery $board, Entitlements $entitlements): mixed
    {
        $user = $this->user();

        /** @var array{branch?: string|null, department?: string|null, employee?: string|null, group?: string|null} $filters */
        $filters = [
            'branch' => $this->branch === '' ? null : $this->branch,
            'department' => $this->department === '' ? null : $this->department,
            'employee' => $this->employee === '' ? null : $this->employee,
            'group' => $this->group === '' ? null : $this->group,
        ];

        try {
            $rows = $board->forDay($user, $this->date === '' ? null : $this->date, $filters);
        } catch (AuthorizationException $e) {
            $this->error = $e->getMessage();
            $rows = [];
        }

        $branchQuery = Branch::query()->active();
        $user->branchScope()->applyTo($branchQuery, 'id');

        return view('livewire.center.journeyBoard', [
            'rows' => $rows,
            'grouped' => $this->group($rows),
            'openRow' => $this->openRow($rows),
            'branches' => $branchQuery->get(),
            'departments' => Department::query()->active()->get(),
            'employees' => Employee::query()->orderBy('id')->get(),
            'noteAdvisory' => NoteAdvisory::text(),
            'canManage' => $user->hasPermission(Permission::JourneyManage),
            'canStart' => $user->hasPermission(Permission::JourneyStageStart),
            'canComplete' => $user->hasPermission(Permission::JourneyStageComplete),
            'canReassign' => $user->hasPermission(Permission::JourneyStageReassign),
            'canNote' => $user->hasPermission(Permission::JourneyNoteManage),
            // Only a flag for a link: the till enforces `pos` and `sale.create`
            // itself, and this board never calls Sales (docs/18-SALES.md §33).
            'canCheckout' => $user->hasPermission(Permission::SaleCreate) && $entitlements->enabled('pos'),
        ]);
    }

    /**
     * @param  list<BoardRow>  $rows
     * @return array<string, list<BoardRow>>
     */
    private function group(array $rows): array
    {
        $grouped = [
            'not_arrived' => [],
            'waiting' => [],
            'in_service' => [],
            'completed' => [],
            'abandoned' => [],
        ];

        foreach ($rows as $row) {
            $grouped[$row->group()][] = $row;
        }

        return $grouped;
    }

    /**
     * @param  list<BoardRow>  $rows
     */
    private function openRow(array $rows): ?BoardRow
    {
        if ($this->openJourney === '') {
            return null;
        }

        foreach ($rows as $row) {
            if ($row->journey?->uuid === $this->openJourney) {
                return $row;
            }
        }

        return null;
    }

    private function transition(
        string $uuid,
        StageStatus $target,
        TransitionStage $transition,
        ?string $reason = null,
    ): void {
        $this->run(function () use ($uuid, $target, $transition, $reason): void {
            $stage = $this->stage($uuid);

            if ($stage === null) {
                return;
            }

            $transition($stage, $target, $this->user(), ['reason' => $reason]);

            $this->saved = __('Updated.');
        });
    }

    private function appointment(string $uuid): ?Appointment
    {
        $query = Appointment::query()->where('uuid', $uuid);

        $this->user()->branchScope()->applyTo($query, 'branch_id');

        return $query->first();
    }

    private function journey(string $uuid): ?ServiceJourney
    {
        $journey = ServiceJourney::query()
            ->with(['stages', 'appointment'])
            ->where('uuid', $uuid)
            ->first();

        return $journey instanceof ServiceJourney
            && $this->user()->canAccessBranch($journey->branchId())
                ? $journey
                : null;
    }

    private function stage(string $uuid): ?JourneyStage
    {
        $stage = JourneyStage::query()
            ->with(['journey.appointment', 'item'])
            ->where('uuid', $uuid)
            ->first();

        return $stage instanceof JourneyStage
            && $this->user()->canAccessBranch($stage->journey->branchId())
                ? $stage
                : null;
    }

    private function run(callable $operation): void
    {
        $this->error = null;
        $this->saved = null;

        try {
            $operation();
        } catch (JourneyFailed|ValidationException|AuthorizationException $e) {
            $this->error = $e instanceof ValidationException
                ? $e->validator->errors()->first()
                : $e->getMessage();
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }
}
