<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\Actions\AbandonQueuedVisit;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CancelTicket;
use App\Modules\Queue\Application\Actions\ChangeTicketPriority;
use App\Modules\Queue\Application\Actions\CompleteServingTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\HoldTicket;
use App\Modules\Queue\Application\Actions\IssueTicket;
use App\Modules\Queue\Application\Actions\ResumeTicket;
use App\Modules\Queue\Application\Actions\SaveDisplay;
use App\Modules\Queue\Application\Actions\SaveServicePoint;
use App\Modules\Queue\Application\Actions\StartServingTicket;
use App\Modules\Queue\Application\Actions\TransferTicket;
use App\Modules\Queue\Application\QueueBoardQuery;
use App\Modules\Queue\Application\QueuePresenter;
use App\Modules\Queue\Application\TicketPrinter;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The staff queue surface: issue, call, hold, transfer, and the board.
 *
 * Every method validates, calls ONE Action, and returns a resource. There is no
 * transition logic here — the Livewire board calls the same Actions, and an
 * architecture test scans controllers for queue state writes
 * (docs/17-QUEUE.md §21).
 *
 * ## Two endpoints that deliberately do not exist
 *
 * There is no "mark this ticket completed" and no "set this ticket to serving".
 * Both follow the JOURNEY fact, through `start` and `complete` below, which
 * call Journey's Action and let the synchronizer move the ticket. A queue
 * endpoint that could do either directly would let a screen say a customer was
 * served while their stage was still waiting (correction 3).
 */
final class QueueController extends Controller
{
    public function board(Request $request, QueueBoardQuery $board, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'string'],
            'department' => ['nullable', 'string'],
            'service_point' => ['nullable', 'string'],
            'employee' => ['nullable', 'string'],
            'state' => ['nullable', 'string', 'in:waiting,called,serving,held,completed,cancelled'],
        ]);

        /** @var array{branch?: string|null, department?: string|null, service_point?: string|null, employee?: string|null, state?: string|null} $filters */
        $filters = $validated;

        $tickets = $board->forDay($user, $validated['date'] ?? null, $filters);

        return ApiResponse::data([
            'tickets' => array_map(
                fn (QueueTicket $ticket): array => $presenter->ticket($ticket, $user),
                $tickets,
            ),
        ]);
    }

    public function show(Request $request, string $uuid, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ticket = $this->ticket($uuid, $user);
        $ticket->load('events.servicePoint');

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user, withHistory: true)]);
    }

    public function issue(Request $request, IssueTicket $issue, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'stage' => ['required', 'string'],
            'service_point' => ['nullable', 'string'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
        ]);

        $stage = $this->stage($validated['stage'], $user);

        $ticket = $issue($stage, $user, [
            'service_point' => $validated['service_point'] ?? null,
            'priority' => isset($validated['priority']) ? (int) $validated['priority'] : null,
        ]);

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)], 201);
    }

    public function walkIn(Request $request, CreateWalkInTicket $create, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'branch' => ['required', 'string'],
            'services' => ['required', 'array', 'min:1'],
            'services.*' => ['required', 'string'],
            'customer' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'employee' => ['nullable', 'string'],
            'idempotency_token' => ['nullable', 'string', 'max:64'],
            'service_point' => ['nullable', 'string'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:255'],
        ]);

        $result = $create(WalkInRequest::fromArray($validated), $user, [
            'service_point' => $validated['service_point'] ?? null,
            'priority' => isset($validated['priority']) ? (int) $validated['priority'] : null,
        ]);

        return ApiResponse::data([
            'visit' => ['uuid' => $result['journey']->uuid],
            'ticket' => $presenter->ticket($result['ticket'], $user),
        ], 201);
    }

    public function call(Request $request, string $uuid, CallTicket $call, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['service_point' => ['nullable', 'string']]);

        // Call and recall are ONE endpoint, because they are one operator
        // gesture: press the button again. The Action decides which it was from
        // the ticket's current state (§13).
        $ticket = $call($this->ticket($uuid, $user), $user, $validated['service_point'] ?? null);

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    public function hold(Request $request, string $uuid, HoldTicket $hold, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'skipped' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $ticket = $hold(
            $this->ticket($uuid, $user),
            $user,
            (bool) ($validated['skipped'] ?? false),
            $validated['reason'] ?? null,
        );

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    public function resume(Request $request, string $uuid, ResumeTicket $resume, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ticket = $resume($this->ticket($uuid, $user), $user);

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    public function transfer(Request $request, string $uuid, TransferTicket $transfer, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'service_point' => ['nullable', 'string'],
            'department' => ['nullable', 'string'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $ticket = $transfer(
            $this->ticket($uuid, $user),
            $user,
            $validated['service_point'] ?? null,
            $validated['department'] ?? null,
            $validated['reason'] ?? null,
        );

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    public function priority(Request $request, string $uuid, ChangeTicketPriority $change, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'priority' => ['required', 'integer', 'min:0', 'max:255'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $ticket = $change(
            $this->ticket($uuid, $user),
            (int) $validated['priority'],
            $user,
            $validated['reason'] ?? null,
        );

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    public function cancel(Request $request, string $uuid, CancelTicket $cancel, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $ticket = $cancel($this->ticket($uuid, $user), $user, $validated['reason'] ?? null);

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    /**
     * Begin the service — through Journey, never by writing the ticket.
     */
    public function start(Request $request, string $uuid, StartServingTicket $start, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ticket = $start($this->ticket($uuid, $user), $user);

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    /**
     * Finish the service — again through Journey, which closes the ticket.
     */
    public function complete(Request $request, string $uuid, CompleteServingTicket $complete, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $ticket = $complete($this->ticket($uuid, $user), $user);

        return ApiResponse::data(['ticket' => $presenter->ticket($ticket, $user)]);
    }

    /**
     * The customer left the center: one orchestration, three consequences.
     */
    public function abandon(Request $request, string $uuid, AbandonQueuedVisit $abandon): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:190']]);

        $journey = ServiceJourney::query()->with('appointment')->where('uuid', $uuid)->first();

        if (! $journey instanceof ServiceJourney || ! $user->canAccessBranch($journey->branchId())) {
            throw new NotFoundHttpException;
        }

        $abandon($journey, $user, $validated['reason'] ?? null);

        return ApiResponse::data(['visit' => ['uuid' => $journey->uuid, 'status' => $journey->status->value]]);
    }

    /**
     * What to print. A payload, not a document — the view is the renderer.
     */
    public function print(Request $request, string $uuid, TicketPrinter $printer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data(['ticket' => $printer->payload($this->ticket($uuid, $user), $user)]);
    }

    public function servicePoints(Request $request, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $points = QueueServicePoint::query()
            ->with(['branch', 'department', 'resource'])
            ->orderBy('branch_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (QueueServicePoint $point): bool => $user->canAccessBranch((int) $point->branch_id))
            ->values();

        return ApiResponse::data([
            'service_points' => $points
                ->map(fn (QueueServicePoint $point): array => $presenter->servicePoint($point))
                ->all(),
        ]);
    }

    public function storeServicePoint(Request $request, SaveServicePoint $save, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $this->servicePointRules($request);

        $point = $save($validated, $user);

        return ApiResponse::data(['service_point' => $presenter->servicePoint($point->load(['branch', 'department', 'resource']))], 201);
    }

    public function updateServicePoint(Request $request, string $uuid, SaveServicePoint $save, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $point = $this->servicePoint($uuid, $user);

        $updated = $save($this->servicePointRules($request), $user, $point);

        return ApiResponse::data(['service_point' => $presenter->servicePoint($updated->load(['branch', 'department', 'resource']))]);
    }

    public function archiveServicePoint(Request $request, string $uuid, SaveServicePoint $save, QueuePresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $point = $save->archive($this->servicePoint($uuid, $user), $user);

        return ApiResponse::data(['service_point' => $presenter->servicePoint($point->load(['branch', 'department', 'resource']))]);
    }

    public function storeDisplay(Request $request, SaveDisplay $save): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'branch' => ['required', 'string'],
            'name' => ['required', 'string', 'max:190'],
            'department' => ['nullable', 'string'],
            'service_point' => ['nullable', 'string'],
            'locale' => ['nullable', 'string', 'max:12'],
            'recent_calls_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            'sound_enabled' => ['nullable', 'boolean'],
            'voice_enabled' => ['nullable', 'boolean'],
            'voice_locales' => ['nullable', 'array'],
            'voice_locales.*' => ['string', 'max:12'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $display = $save($validated, $user);

        return ApiResponse::data([
            'display' => [
                'uuid' => $display->uuid,
                'name' => $display->name,
                // The public key IS returned here, once, to the person who just
                // configured the screen — it is the URL they have to open on it.
                'public_key' => $display->public_key,
                'is_active' => $display->is_active,
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function servicePointRules(Request $request): array
    {
        return $request->validate([
            'branch' => ['nullable', 'string'],
            'name' => ['nullable', 'array'],
            'display_code' => ['nullable', 'string', 'max:8'],
            'ticket_prefix' => ['nullable', 'string', 'max:4'],
            'department' => ['nullable', 'string'],
            'resource' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);
    }

    private function ticket(string $uuid, User $user): QueueTicket
    {
        $ticket = QueueTicket::query()
            ->with(['journey.customer', 'journey.appointment.customer', 'stage', 'department', 'servicePoint'])
            ->where('uuid', $uuid)
            ->first();

        // A ticket at another branch is NOT FOUND, not forbidden: 404 is what
        // every other cross-scope read in this product answers
        // (docs/08-AUDIT-SECURITY.md).
        if (! $ticket instanceof QueueTicket || ! $user->canAccessBranch((int) $ticket->branch_id)) {
            throw new NotFoundHttpException;
        }

        return $ticket;
    }

    private function servicePoint(string $uuid, User $user): QueueServicePoint
    {
        $point = QueueServicePoint::query()->with('branch')->where('uuid', $uuid)->first();

        if (! $point instanceof QueueServicePoint || ! $user->canAccessBranch((int) $point->branch_id)) {
            throw new NotFoundHttpException;
        }

        return $point;
    }

    private function stage(string $uuid, User $user): JourneyStage
    {
        $stage = JourneyStage::query()
            ->with(['journey.appointment', 'department'])
            ->where('uuid', $uuid)
            ->first();

        if (! $stage instanceof JourneyStage || ! $user->canAccessBranch($stage->journey->branchId())) {
            throw new NotFoundHttpException;
        }

        return $stage;
    }
}
