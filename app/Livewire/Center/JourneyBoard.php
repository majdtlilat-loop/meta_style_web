<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Livewire\Center\Queue\OperationalFailure;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Application\JourneyBoardQuery;
use App\Modules\ServiceJourney\Application\JourneyBoardView;
use App\Modules\ServiceJourney\Application\VisitOptions;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\View\Manager\FeatureOffer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Today's floor: who is expected, who is here, who is in a chair, who is done.
 *
 * ## Not the queue
 *
 * A staff working board — no numbers, no calling, no television. The queue is
 * built on top of these tables and has its own screen (docs/16, docs/17).
 *
 * ## Nothing here decides anything
 *
 * Every button calls the Action the API calls (CheckInAppointment,
 * TransitionStage, …); the transitions, resource holds, branch scope and audit
 * entries live there. Which buttons a card shows is `JourneyBoardView`'s
 * answer. The visit drawer and the walk-in form are child components, so the
 * board's poll never wipes a note or a reason being typed.
 *
 * ## Locked
 *
 * Journey is the operational half of `booking`. Without it the board is the
 * upgrade offer — unless the center has visits already, which stay readable
 * with every action hidden.
 */
#[Layout('components.layouts.app')]
final class JourneyBoard extends Component
{
    use RequiresFeature;
    use RunsDeskActions;

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

    /**
     * The lane a phone shows, and whether its filters are open. Server state on
     * purpose: a client-side toggle would be snapped back by the poll.
     */
    public string $lane = 'waiting';

    public bool $filtersOpen = false;

    public function mount(): void
    {
        if (! $this->viewer()->hasPermission(Permission::JourneyView)
            && ! $this->viewer()->hasPermission(Permission::JourneyViewOwn)) {
            throw new AuthorizationException('You may not view the visit board.');
        }

        $branches = app(VisitOptions::class)->branches($this->viewer());

        if ($this->branch === '' && count($branches) === 1) {
            $this->branch = $branches[0]['uuid'];
        }
    }

    public function updatedBranch(): void
    {
        $this->employee = '';
    }

    public function checkIn(string $appointmentUuid, JourneyBoardQuery $board, CheckInAppointment $checkIn): void
    {
        $this->attempt(function () use ($appointmentUuid, $board, $checkIn): void {
            $journey = $checkIn($board->appointment($this->viewer(), $appointmentUuid), $this->viewer());

            $this->succeeded(__('manager_visits.notices.checked_in'));
            $this->openVisit($journey->uuid);
        });
    }

    public function start(string $stageUuid, JourneyBoardQuery $board, TransitionStage $transition): void
    {
        $this->attempt(function () use ($stageUuid, $board, $transition): void {
            $transition($board->stage($this->viewer(), $stageUuid), StageStatus::InService, $this->viewer());

            $this->succeeded(__('manager_visits.notices.started'));
        });
    }

    public function finish(string $stageUuid, JourneyBoardQuery $board, TransitionStage $transition): void
    {
        $this->attempt(function () use ($stageUuid, $board, $transition): void {
            $transition($board->stage($this->viewer(), $stageUuid), StageStatus::Completed, $this->viewer());

            $this->succeeded(__('manager_visits.notices.finished'));
        });
    }

    public function openVisit(string $journeyUuid): void
    {
        $this->dispatch('open-visit', uuid: $journeyUuid)->to(Journey\VisitPanel::class);
    }

    public function openWalkIn(): void
    {
        $this->dispatch('open-walk-in', branch: $this->branch, mode: 'visit')->to(Queue\WalkInForm::class);
    }

    #[On('walk-in-created')]
    public function walkInCreated(?string $journey = null, ?string $number = null): void
    {
        $this->succeeded($number !== null
            ? __('manager_visits.notices.walk_in_ticket', ['number' => $number])
            : __('manager_visits.notices.walk_in'));

        if ($journey !== null) {
            $this->openVisit($journey);
        }
    }

    #[On('visit-changed')]
    public function visitChanged(string $message = ''): void
    {
        if ($message !== '') {
            $this->succeeded($message);
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['date', 'department', 'employee', 'group']);
    }

    public function render(JourneyBoardQuery $board, JourneyBoardView $view, VisitOptions $options, Entitlements $entitlements): View
    {
        $viewer = $this->viewer();
        $entitled = $entitlements->enabled('booking');

        if (! $entitled && ! $board->hasHistory() && ($locked = $this->lockedView('booking'))) {
            return $locked;
        }

        $rows = [];
        $date = $this->date === '' ? null : $this->date;

        try {
            $rows = $board->forDay($viewer, $date, array_filter([
                'branch' => $this->branch,
                'department' => $this->department,
                'employee' => $this->employee,
            ]));
        } catch (AuthorizationException $failure) {
            $this->succeeded(OperationalFailure::message($failure), 'danger');
        }

        $cards = $view->cards($rows, $viewer, $entitled);
        $counts = $view->counts($cards);
        $lanes = $view->lanes($cards);

        if ($this->group !== '' && isset($lanes[$this->group])) {
            $lanes = [$this->group => $lanes[$this->group]];
        }

        $branch = $options->branch($viewer, $this->branch);
        $home = $branch ?? $options->branch($viewer, $options->branches($viewer)[0]['uuid'] ?? '');
        $today = $home === null ? null : BranchClock::localDate(CarbonImmutable::now()->utc(), $home->timezone);
        $isToday = $date === null || $date === $today;

        return view('livewire.center.journeyBoard', [
            'readOnly' => ! $entitled,
            'offer' => $entitled ? null : app(FeatureOffer::class)->for('booking', null, $viewer),
            'lanes' => $lanes,
            'counts' => $counts,
            'branches' => $options->branches($viewer),
            'departments' => $options->departments(),
            'team' => $options->teamInScope($viewer, $branch),
            'isToday' => $isToday,
            'today' => $today,
            'canWalkIn' => $entitled && $viewer->hasPermission(Permission::JourneyWalkInCreate),
            'canQueue' => $viewer->hasPermission(Permission::QueueView) && $entitlements->enabled('queue_management'),
            'canCheckout' => $viewer->hasPermission(Permission::SaleCreate) && $entitlements->enabled('pos'),
            'hasFilters' => $this->department !== '' || $this->employee !== '' || $this->group !== '' || ! $isToday,
        ]);
    }
}
