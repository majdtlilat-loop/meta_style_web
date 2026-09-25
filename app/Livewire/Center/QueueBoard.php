<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Livewire\Center\Queue\OperationalFailure;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CompleteServingTicket;
use App\Modules\Queue\Application\Actions\HoldTicket;
use App\Modules\Queue\Application\Actions\IssueTicket;
use App\Modules\Queue\Application\Actions\ResumeTicket;
use App\Modules\Queue\Application\Actions\StartServingTicket;
use App\Modules\Queue\Application\QueueBoardQuery;
use App\Modules\Queue\Application\QueueBoardView;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\JourneyBoardQuery;
use App\Modules\ServiceJourney\Application\VisitOptions;
use App\View\Manager\FeatureOffer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The reception queue: who is waiting, who has been called, who is in a chair.
 *
 * ## Nothing here decides anything
 *
 * Every button calls the Action the API calls — the ticket lock, the history
 * row, the branch scope and the audit entry live there, so this screen and the
 * API can never drift apart (docs/17-QUEUE.md §21). Which buttons a card shows
 * is `QueueBoardView`'s answer, from the state map and the viewer's grants.
 *
 * Start and finish go through the QUEUE ORCHESTRATIONS, which call Journey:
 * `serving` and `completed` are Journey's to give (ADR-053). A held ticket is
 * never offered "call" — resume puts it back in its place.
 *
 * ## Polling, deliberately
 *
 * `wire:poll.5s.visible` while the board shows today (§15). The forms — the
 * walk-in, the ticket drawer, the desk setup — are child components, so a
 * poll re-renders the lanes and never a half-typed reason.
 *
 * ## Locked
 *
 * Without `queue_management` the page is the upgrade offer — unless this center
 * has tickets already, in which case the history stays readable with every
 * action hidden (docs/17 §19: withdrawal blocks new operations only).
 */
#[Layout('components.layouts.app')]
final class QueueBoard extends Component
{
    use RequiresFeature;
    use RunsDeskActions;

    #[Url]
    public string $tab = 'board';

    #[Url]
    public string $date = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $department = '';

    #[Url]
    public string $servicePoint = '';

    /** The desk this host calls customers to (a service point uuid). */
    #[Url]
    public string $desk = '';

    /**
     * The lane a phone shows, and whether its filters are open. Server state on
     * purpose: a client-side toggle would be snapped back by the 5-second poll.
     */
    public string $lane = 'waiting';

    public bool $filtersOpen = false;

    /** The last ticket issued here, for the print button. */
    public string $issuedTicket = '';

    public string $issuedNumber = '';

    public function mount(): void
    {
        abort_unless($this->viewer()->hasPermission(Permission::QueueView), 403);

        // One branch in scope: choose it, so "call next" and the desk list work
        // without a click. Several: all of them, until the host narrows it.
        $branches = app(VisitOptions::class)->branches($this->viewer());

        if ($this->branch === '' && count($branches) === 1) {
            $this->branch = $branches[0]['uuid'];
        }

        if ($this->tab !== 'setup') {
            $this->tab = 'board';
        }
    }

    public function updatedBranch(): void
    {
        // A desk belongs to one branch.
        $this->reset(['desk', 'servicePoint']);
    }

    /*
     * Not `call`: in the browser `wire:click="call(…)"` resolves to Livewire's
     * reserved `$wire.call(method, …params)`, which turns the ticket uuid into
     * the METHOD name (MethodNotFoundException). tests/Architecture/
     * LivewireActionNamesTest.php keeps every reserved `$wire` name out.
     */
    public function callTicket(string $uuid, QueueBoardQuery $board, CallTicket $call): void
    {
        $this->attempt(function () use ($uuid, $board, $call): void {
            $found = $board->find($this->viewer(), $uuid);
            $ticket = $call($found, $this->viewer(), $this->deskFor($board, $found));

            $this->succeeded($ticket->call_count > 1
                ? __('manager_queue.notices.recalled', ['number' => $ticket->display_number])
                : __('manager_queue.notices.called', ['number' => $ticket->display_number]));
        });
    }

    public function callNext(QueueBoardQuery $board, CallTicket $call): void
    {
        $this->attempt(function () use ($board, $call): void {
            $branch = app(VisitOptions::class)->branch($this->viewer(), $this->branch);

            if ($branch === null) {
                $this->succeeded(__('manager_queue.notices.choose_branch'), 'warning');

                return;
            }

            $next = $board->nextToCall($branch, $this->date === '' ? null : $this->date, array_filter([
                'department' => $this->department,
                'service_point' => $this->servicePoint,
            ]));

            if (! $next instanceof QueueTicket) {
                $this->succeeded(__('manager_queue.notices.nobody_waiting'), 'info');

                return;
            }

            $ticket = $call($next, $this->viewer(), $this->deskFor($board, $next));

            $this->succeeded(__('manager_queue.notices.called', ['number' => $ticket->display_number]));
        });
    }

    public function skip(string $uuid, QueueBoardQuery $board, HoldTicket $hold): void
    {
        $this->attempt(function () use ($uuid, $board, $hold): void {
            $ticket = $hold($board->find($this->viewer(), $uuid), $this->viewer(), true);

            $this->succeeded(__('manager_queue.notices.skipped', ['number' => $ticket->display_number]), 'info');
        });
    }

    public function hold(string $uuid, QueueBoardQuery $board, HoldTicket $hold): void
    {
        $this->attempt(function () use ($uuid, $board, $hold): void {
            $ticket = $hold($board->find($this->viewer(), $uuid), $this->viewer(), false);

            $this->succeeded(__('manager_queue.notices.held', ['number' => $ticket->display_number]), 'info');
        });
    }

    public function resume(string $uuid, QueueBoardQuery $board, ResumeTicket $resume): void
    {
        $this->attempt(function () use ($uuid, $board, $resume): void {
            $ticket = $resume($board->find($this->viewer(), $uuid), $this->viewer());

            $this->succeeded(__('manager_queue.notices.resumed', ['number' => $ticket->display_number]));
        });
    }

    public function start(string $uuid, QueueBoardQuery $board, StartServingTicket $start): void
    {
        $this->attempt(function () use ($uuid, $board, $start): void {
            $ticket = $start($board->find($this->viewer(), $uuid), $this->viewer());

            $this->succeeded(__('manager_queue.notices.started', ['number' => $ticket->display_number]));
        });
    }

    public function finish(string $uuid, QueueBoardQuery $board, CompleteServingTicket $complete): void
    {
        $this->attempt(function () use ($uuid, $board, $complete): void {
            $ticket = $complete($board->find($this->viewer(), $uuid), $this->viewer());

            $this->succeeded(__('manager_queue.notices.finished', ['number' => $ticket->display_number]));
        });
    }

    /**
     * A number for a visit that was checked in without one.
     */
    public function issue(string $stageUuid, JourneyBoardQuery $visits, IssueTicket $issue): void
    {
        $this->attempt(function () use ($stageUuid, $visits, $issue): void {
            $ticket = $issue($visits->stage($this->viewer(), $stageUuid), $this->viewer(), ['source' => 'staff']);

            $this->rememberIssued($ticket->uuid, $ticket->display_number);
        });
    }

    public function openTicket(string $uuid): void
    {
        $this->dispatch('queue-open-ticket', uuid: $uuid)->to(Queue\TicketPanel::class);
    }

    public function openWalkIn(): void
    {
        $this->dispatch('open-walk-in', branch: $this->branch, mode: 'queue')->to(Queue\WalkInForm::class);
    }

    #[On('walk-in-created')]
    public function walkInCreated(?string $ticket = null, ?string $number = null): void
    {
        if ($ticket !== null && $number !== null) {
            $this->rememberIssued($ticket, $number);
        }
    }

    #[On('queue-changed')]
    public function queueChanged(string $message = '', string $tone = 'success'): void
    {
        if ($message !== '') {
            $this->succeeded($message, $tone);
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'setup' && $this->viewer()->hasPermission(Permission::QueueDisplayManage) ? 'setup' : 'board';
    }

    public function render(QueueBoardQuery $board, QueueBoardView $view, Entitlements $entitlements, VisitOptions $options): View
    {
        $viewer = $this->viewer();
        $readOnly = false;

        if (! $entitlements->enabled('queue_management')) {
            if (! $board->hasHistory() && ($locked = $this->lockedView('queue_management'))) {
                return $locked;
            }

            $readOnly = true;
        }

        $filters = array_filter([
            'branch' => $this->branch,
            'department' => $this->department,
            'service_point' => $this->servicePoint,
        ]);
        $date = $this->date === '' ? null : $this->date;
        [$tickets, $pending, $points] = [[], [], []];

        try {
            $points = $board->servicePoints($viewer, $this->branch === '' ? null : $this->branch);

            if ($this->tab === 'board') {
                $tickets = $board->forDay($viewer, $date, $filters);
                $pending = $readOnly ? [] : $board->awaitingNumber($viewer, $date, $filters);
            }
        } catch (AuthorizationException $failure) {
            // A branch in the URL this viewer does not work in.
            $this->succeeded(OperationalFailure::message($failure), 'danger');
        }

        $home = $board->defaultBranch($viewer);
        $today = $home === null ? null : BranchClock::localDate(CarbonImmutable::now()->utc(), $home->timezone);
        $isToday = $date === null || $date === $today;

        return view('livewire.center.queueBoard', [
            'readOnly' => $readOnly,
            'offer' => $readOnly ? app(FeatureOffer::class)->for('queue_management', null, $viewer) : null,
            'flags' => $view->flags($viewer),
            'lanes' => $view->lanes($view->cards($tickets, $viewer)),
            'summary' => $view->summary($tickets),
            'pending' => array_map(static fn (array $row): array => $view->pending($row), $pending),
            'branches' => $options->branches($viewer),
            'departments' => $options->departments(),
            'points' => array_map(static fn (QueueServicePoint $p): array => [
                'uuid' => $p->uuid,
                'code' => $p->display_code,
                'name' => (string) $p->name->get(),
            ], $points),
            'isToday' => $isToday,
            'today' => $today,
            'hasFilters' => $this->department !== '' || $this->servicePoint !== '' || ! $isToday,
        ]);
    }

    public function clearFilters(): void
    {
        $this->reset(['department', 'servicePoint', 'date']);
    }

    private function rememberIssued(string $uuid, string $number): void
    {
        $this->issuedTicket = $uuid;
        $this->issuedNumber = $number;
        $this->succeeded(__('manager_queue.notices.issued', ['number' => $number]));

        // The browser prints only if this desk asked for it (a per-device
        // preference, resources/js/manager/queue.js).
        if ($this->viewer()->hasPermission(Permission::QueueTicketPrint) && app(Entitlements::class)->enabled('printing')) {
            $this->dispatch('queue-ticket-issued', url: route('center.queue.ticket', ['uuid' => $uuid]));
        }
    }

    /**
     * The desk to call to — unless the ticket stands at another branch, where
     * this desk does not exist and the Action would refuse it.
     */
    private function deskFor(QueueBoardQuery $board, QueueTicket $ticket): ?string
    {
        if ($this->desk === '') {
            return null;
        }

        foreach ($board->servicePoints($this->viewer()) as $point) {
            if ($point->uuid === $this->desk) {
                return (int) $point->branch_id === (int) $ticket->branch_id ? $this->desk : null;
            }
        }

        return null;
    }
}
