<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Queue\Application\Actions\AbandonQueuedVisit;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CancelTicket;
use App\Modules\Queue\Application\Actions\CompleteServingTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\HoldTicket;
use App\Modules\Queue\Application\Actions\ResumeTicket;
use App\Modules\Queue\Application\Actions\StartServingTicket;
use App\Modules\Queue\Application\Actions\TransferTicket;
use App\Modules\Queue\Application\QueueBoardQuery;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The reception queue: take a walk-in, hand out a number, call it.
 *
 * ## Nothing here decides anything
 *
 * Every button calls the Action the API calls. The state machine, the ticket
 * lock, the history rows, the branch scope and the audit entries live there, so
 * this screen and the API can never drift apart
 * (docs/04-MODULE-BOUNDARIES.md, docs/17-QUEUE.md §21).
 *
 * In particular, start and finish go through the QUEUE ORCHESTRATIONS, which
 * call Journey — so pressing "start" here and pressing "start" on the visit
 * board produce exactly the same events in exactly the same order
 * (correction 4).
 *
 * ## Polling, deliberately
 *
 * `wire:poll` and a refresh button. Phase 8 evaluated Reverb and SSE and chose
 * neither: a queue must stay correct when delivery fails, so the database is
 * the source of truth and the screen re-reads it. The realtime decision and its
 * trade-offs are written down in docs/17-QUEUE.md §15 rather than assumed.
 *
 * ## The walk-in form is short on purpose
 *
 * Reception is standing in front of somebody. Phone, name, services, an
 * optional stylist — and a token so a double-click cannot produce two visits
 * (§22, §59).
 */
#[Layout('components.layouts.app')]
final class QueueBoard extends Component
{
    #[Url]
    public string $date = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $servicePoint = '';

    #[Url]
    public string $state = '';

    /** The ticket whose detail panel is open. */
    public string $openTicket = '';

    // ---- walk-in form -----------------------------------------------------

    public bool $walkInOpen = false;

    public string $walkInName = '';

    public string $walkInPhone = '';

    /** @var list<string> */
    public array $walkInServices = [];

    public string $walkInEmployee = '';

    /**
     * One token per form opening.
     *
     * The database refuses the second submission rather than trusting a
     * disabled button — a browser that fires twice, a flaky connection retried
     * by the user, and a genuine double-click all arrive the same way (§59).
     */
    public string $walkInToken = '';

    // ---- action inputs ----------------------------------------------------

    public string $callServicePoint = '';

    public string $holdReason = '';

    public string $transferServicePoint = '';

    public string $transferDepartment = '';

    public string $abandonReason = '';

    public string $error = '';

    public string $saved = '';

    /** The payload the print view renders, once, after issuing. */
    public string $printTicket = '';

    public function mount(): void
    {
        $this->walkInToken = (string) Str::uuid();
    }

    public function openWalkIn(): void
    {
        $this->walkInOpen = true;
        // A fresh token for a fresh customer: reusing one would make the
        // SECOND walk-in silently return the first one's visit.
        $this->walkInToken = (string) Str::uuid();
        $this->reset(['walkInName', 'walkInPhone', 'walkInServices', 'walkInEmployee', 'error', 'saved']);
    }

    public function createWalkIn(CreateWalkInTicket $create): void
    {
        $this->guard(function () use ($create): void {
            $result = $create(
                new WalkInRequest(
                    branchUuid: $this->branchUuid(),
                    serviceUuids: array_values(array_filter($this->walkInServices, 'is_string')),
                    name: $this->walkInName === '' ? null : $this->walkInName,
                    phone: $this->walkInPhone === '' ? null : $this->walkInPhone,
                    employeeUuid: $this->walkInEmployee === '' ? null : $this->walkInEmployee,
                    idempotencyToken: $this->walkInToken,
                ),
                $this->user(),
            );

            $this->walkInOpen = false;
            $this->printTicket = $result['ticket']->uuid;
            $this->saved = __('Ticket :number issued.', ['number' => $result['ticket']->display_number]);
        });
    }

    public function call(string $uuid, CallTicket $call): void
    {
        $this->guard(function () use ($uuid, $call): void {
            $ticket = $call(
                $this->ticket($uuid),
                $this->user(),
                $this->callServicePoint === '' ? null : $this->callServicePoint,
            );

            $this->saved = $ticket->call_count > 1
                ? __('Called again.')
                : __('Called.');
        });
    }

    public function hold(string $uuid, bool $skipped, HoldTicket $hold): void
    {
        $this->guard(function () use ($uuid, $skipped, $hold): void {
            $hold(
                $this->ticket($uuid),
                $this->user(),
                $skipped,
                $this->holdReason === '' ? null : $this->holdReason,
            );

            $this->reset(['holdReason']);
            $this->saved = $skipped
                // Said plainly, because a host needs to know the customer is
                // recoverable rather than gone (§15).
                ? __('Skipped. The ticket is on hold and can be resumed if they come back.')
                : __('On hold.');
        });
    }

    public function resume(string $uuid, ResumeTicket $resume): void
    {
        $this->guard(function () use ($uuid, $resume): void {
            $resume($this->ticket($uuid), $this->user());

            $this->saved = __('Back in the queue, in their original place.');
        });
    }

    public function transfer(string $uuid, TransferTicket $transfer): void
    {
        $this->guard(function () use ($uuid, $transfer): void {
            $transfer(
                $this->ticket($uuid),
                $this->user(),
                $this->transferServicePoint === '' ? null : $this->transferServicePoint,
                $this->transferDepartment === '' ? null : $this->transferDepartment,
            );

            $this->reset(['transferServicePoint', 'transferDepartment']);
            $this->saved = __('Transferred. Call them again to the new destination.');
        });
    }

    public function start(string $uuid, StartServingTicket $start): void
    {
        $this->guard(function () use ($uuid, $start): void {
            $start($this->ticket($uuid), $this->user());

            $this->saved = __('Service started.');
        });
    }

    public function complete(string $uuid, CompleteServingTicket $complete): void
    {
        $this->guard(function () use ($uuid, $complete): void {
            $complete($this->ticket($uuid), $this->user());

            $this->saved = __('Service finished.');
        });
    }

    public function cancel(string $uuid, CancelTicket $cancel): void
    {
        $this->guard(function () use ($uuid, $cancel): void {
            $cancel($this->ticket($uuid), $this->user());

            $this->saved = __('Ticket cancelled. The visit itself was not changed.');
        });
    }

    public function abandon(string $uuid, AbandonQueuedVisit $abandon): void
    {
        $this->guard(function () use ($uuid, $abandon): void {
            $ticket = $this->ticket($uuid);
            $journey = $ticket->journey;

            if (! $journey instanceof ServiceJourney) {
                throw QueueFailed::policy('That ticket is not attached to a visit.');
            }

            $abandon($journey, $this->user(), $this->abandonReason === '' ? null : $this->abandonReason);

            $this->reset(['abandonReason']);
            $this->openTicket = '';
            $this->saved = __('Visit ended and its tickets closed.');
        });
    }

    public function refresh(): void
    {
        $this->reset(['error', 'saved']);
    }

    public function render(QueueBoardQuery $board): mixed
    {
        $user = $this->user();

        /** @var array{branch?: string|null, department?: string|null, service_point?: string|null, state?: string|null} $filters */
        $filters = [
            'branch' => $this->branch === '' ? null : $this->branch,
            'department' => $this->department === '' ? null : $this->department,
            'service_point' => $this->servicePoint === '' ? null : $this->servicePoint,
            'state' => $this->state === '' ? null : $this->state,
        ];

        try {
            $tickets = $board->forDay($user, $this->date === '' ? null : $this->date, $filters);
        } catch (AuthorizationException $e) {
            $this->error = $e->getMessage();
            $tickets = [];
        }

        $branchQuery = Branch::query()->active();
        $user->branchScope()->applyTo($branchQuery, 'id');

        return view('livewire.center.queueBoard', [
            'tickets' => $tickets,
            'grouped' => $this->group($tickets),
            'branches' => $branchQuery->get(),
            'departments' => Department::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'servicePoints' => QueueServicePoint::query()->usable()->orderBy('sort_order')->get(),
            'services' => Service::query()->active()->orderBy('sort_order')->get(),
            'canCall' => $user->hasPermission(Permission::QueueCall),
            'canManage' => $user->hasPermission(Permission::QueueManage),
            'canWalkIn' => $user->hasPermission(Permission::JourneyWalkInCreate),
            'canPrint' => $user->hasPermission(Permission::QueueTicketPrint),
        ]);
    }

    /**
     * The five operational columns.
     *
     * DERIVED from state, never stored. A `board_column` value would be another
     * place the truth lives and the first to go stale.
     *
     * @param  list<QueueTicket>  $tickets
     * @return array<string, list<QueueTicket>>
     */
    private function group(array $tickets): array
    {
        $grouped = [
            TicketState::Waiting->value => [],
            TicketState::Called->value => [],
            TicketState::Serving->value => [],
            TicketState::Held->value => [],
            'closed' => [],
        ];

        foreach ($tickets as $ticket) {
            $key = $ticket->state->isTerminal() ? 'closed' : $ticket->state->value;

            $grouped[$key][] = $ticket;
        }

        return $grouped;
    }

    private function branchUuid(): string
    {
        if ($this->branch !== '') {
            return $this->branch;
        }

        $branch = Branch::query()->active()->orderBy('id')->first();

        return $branch instanceof Branch ? $branch->uuid : '';
    }

    private function ticket(string $uuid): QueueTicket
    {
        $ticket = QueueTicket::query()
            ->with(['journey.customer', 'journey.appointment.customer', 'stage', 'department', 'servicePoint'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return $ticket;
    }

    /**
     * One place where an operational refusal becomes a message.
     *
     * The Actions raise `QueueFailed` and `JourneyFailed` with text written for
     * a reception desk, so it is shown as-is rather than translated into
     * something vaguer here.
     */
    private function guard(callable $work): void
    {
        $this->reset(['error', 'saved']);

        try {
            $work();
        } catch (QueueFailed|JourneyFailed $failure) {
            $this->error = $failure->getMessage();
        } catch (AuthorizationException $failure) {
            $this->error = $failure->getMessage();
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }
}
