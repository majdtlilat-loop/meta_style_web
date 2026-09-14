<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteAdvisory;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Application\Actions\AbortJourney;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\HandoffStage;
use App\Modules\ServiceJourney\Application\Actions\ManageStageNotes;
use App\Modules\ServiceJourney\Application\Actions\ReassignStageEmployee;
use App\Modules\ServiceJourney\Application\Actions\SwapStageResource;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Application\JourneyBoardQuery;
use App\Modules\ServiceJourney\Application\JourneyPresenter;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The operational floor: check in, start, finish, hand on, close.
 *
 * STAFF ONLY. There is no customer-facing journey surface in Phase 7 and no
 * route that would reach one — a customer does not need to know which room they
 * are in or that their stylist was swapped (docs/13-ROADMAP.md Phase 7 §46).
 *
 * Every method validates, calls ONE Action, and returns a resource. The
 * transitions, the resource holds, the audit entries and the branch scope all
 * live in the Actions, because the Livewire board calls exactly the same ones.
 */
final class JourneyController extends Controller
{
    public function board(Request $request, JourneyBoardQuery $board, JourneyPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'string'],
            'department' => ['nullable', 'string'],
            'employee' => ['nullable', 'string'],
            'group' => ['nullable', 'string', 'in:not_arrived,waiting,in_service,completed,abandoned'],
        ]);

        /** @var array{branch?: string|null, department?: string|null, employee?: string|null, group?: string|null} $filters */
        $filters = $validated;

        $rows = $board->forDay($user, $validated['date'] ?? null, $filters);

        return ApiResponse::data([
            'visits' => array_map(fn ($row): array => $presenter->row($row, $user), $rows),
        ]);
    }

    public function checkIn(
        Request $request,
        string $uuid,
        CheckInAppointment $checkIn,
        JourneyPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $journey = $checkIn($this->appointment($uuid, $user), $user);

        // 200, not 201: a repeat check-in returns the journey that already
        // exists and the caller cannot tell whether it created one. That is the
        // point of an idempotent Action (§48).
        return ApiResponse::data(['journey' => $presenter->journey($journey->load('stages'), $user)]);
    }

    public function show(
        Request $request,
        string $uuid,
        JourneyBoardQuery $board,
        JourneyPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $row = $board->detail($this->journey($uuid, $user), $user);

        return ApiResponse::data(['visit' => $presenter->row($row, $user)]);
    }

    public function startStage(
        Request $request,
        string $uuid,
        TransitionStage $transition,
        JourneyPresenter $presenter,
    ): JsonResponse {
        return $this->transition($request, $uuid, StageStatus::InService, $transition, $presenter);
    }

    public function completeStage(
        Request $request,
        string $uuid,
        TransitionStage $transition,
        JourneyPresenter $presenter,
    ): JsonResponse {
        return $this->transition($request, $uuid, StageStatus::Completed, $transition, $presenter);
    }

    public function skipStage(
        Request $request,
        string $uuid,
        TransitionStage $transition,
        JourneyPresenter $presenter,
    ): JsonResponse {
        return $this->transition($request, $uuid, StageStatus::Skipped, $transition, $presenter);
    }

    public function reassignStage(
        Request $request,
        string $uuid,
        ReassignStageEmployee $reassign,
        JourneyPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['employee' => ['required', 'string']]);

        $stage = $reassign($this->stage($uuid, $user), (string) $validated['employee'], $user);

        return ApiResponse::data(['stage' => $presenter->stage($stage->load('resources.resource'), $user)]);
    }

    public function swapResource(
        Request $request,
        string $uuid,
        SwapStageResource $swap,
        JourneyPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $stage = $this->stage($uuid, $user);

        $swap($stage, (string) $validated['from'], (string) $validated['to'], $user, $validated['reason'] ?? null);

        return ApiResponse::data([
            'stage' => $presenter->stage($stage->fresh(['resources.resource', 'item']) ?? $stage, $user),
        ]);
    }

    public function handoff(Request $request, string $uuid, HandoffStage $handoff): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'to_stage' => ['nullable', 'string'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        $record = $handoff(
            $this->stage($uuid, $user),
            $user,
            $validated['to_stage'] ?? null,
            $validated['note'] ?? null,
        );

        return ApiResponse::data(['handoff_uuid' => $record->uuid], 201);
    }

    public function complete(
        Request $request,
        string $uuid,
        CompleteJourney $complete,
        JourneyPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $journey = $complete($this->journey($uuid, $user), $user);

        return ApiResponse::data(['journey' => $presenter->journey($journey, $user)]);
    }

    public function abort(
        Request $request,
        string $uuid,
        AbortJourney $abort,
        JourneyPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $journey = $abort($this->journey($uuid, $user), $user, $validated['reason'] ?? null);

        return ApiResponse::data(['journey' => $presenter->journey($journey, $user)]);
    }

    public function storeNote(Request $request, string $uuid, ManageStageNotes $notes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['nullable', 'string'],
        ]);

        $note = $notes->add(
            $this->stage($uuid, $user),
            (string) $validated['body'],
            $user,
            NoteVisibility::tryFrom((string) ($validated['visibility'] ?? '')) ?? NoteVisibility::Internal,
        );

        // The same advisory every other note surface carries. A mobile client
        // that never reads the docs still gets a string to render above its own
        // text box (§29).
        return ApiResponse::data(['uuid' => $note->uuid], 201, NoteAdvisory::meta());
    }

    public function destroyNote(
        Request $request,
        string $uuid,
        string $noteUuid,
        ManageStageNotes $notes,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $stage = $this->stage($uuid, $user);

        $note = InternalNote::query()
            ->where('uuid', $noteUuid)
            ->where('owner_type', NoteOwner::JourneyStage->value)
            ->first();

        if (! $note instanceof InternalNote) {
            throw new NotFoundHttpException;
        }

        $notes->delete($stage, $note, $user);

        return ApiResponse::data(['deleted' => true]);
    }

    private function transition(
        Request $request,
        string $uuid,
        StageStatus $target,
        TransitionStage $transition,
        JourneyPresenter $presenter,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $stage = $transition($this->stage($uuid, $user), $target, $user, ['reason' => $validated['reason'] ?? null]);

        return ApiResponse::data([
            'stage' => $presenter->stage($stage->load(['resources.resource', 'item']), $user),
        ]);
    }

    /**
     * An appointment in this tenant, in a branch the caller may work in.
     *
     * Out of scope is NOT FOUND, never 403 — a 403 confirms the record exists
     * (docs/08-AUDIT-SECURITY.md).
     */
    private function appointment(string $uuid, User $user): Appointment
    {
        $query = Appointment::query()->where('uuid', $uuid);

        $user->branchScope()->applyTo($query, 'branch_id');

        $appointment = $query->first();

        if (! $appointment instanceof Appointment) {
            throw new NotFoundHttpException;
        }

        return $appointment;
    }

    private function journey(string $uuid, User $user): ServiceJourney
    {
        $journey = ServiceJourney::query()
            ->with(['stages', 'appointment'])
            ->where('uuid', $uuid)
            ->first();

        if (! $journey instanceof ServiceJourney
            || ! $user->canAccessBranch($journey->branchId())) {
            throw new NotFoundHttpException;
        }

        return $journey;
    }

    private function stage(string $uuid, User $user): JourneyStage
    {
        $stage = JourneyStage::query()
            ->with(['journey.appointment', 'item'])
            ->where('uuid', $uuid)
            ->first();

        if (! $stage instanceof JourneyStage
            || ! $user->canAccessBranch($stage->journey->branchId())) {
            throw new NotFoundHttpException;
        }

        return $stage;
    }
}
