<?php

declare(strict_types=1);

namespace App\Livewire\Center\Journey;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Notes\NoteVisibility;
use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Livewire\Center\Queue\OperationalFailure;
use App\Modules\Queue\Application\Actions\IssueTicket;
use App\Modules\ServiceJourney\Application\Actions\AbortJourney;
use App\Modules\ServiceJourney\Application\Actions\CancelVisit;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\HandoffStage;
use App\Modules\ServiceJourney\Application\Actions\ManageStageNotes;
use App\Modules\ServiceJourney\Application\Actions\ReassignStageEmployee;
use App\Modules\ServiceJourney\Application\Actions\SwapStageResource;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Application\JourneyBoardQuery;
use App\Modules\ServiceJourney\Application\JourneyBoardView;
use App\Modules\ServiceJourney\Application\VisitOptions;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One visit, in a drawer: every service with who was booked beside who is
 * doing it, the rooms actually used, notes, hand-offs — and the decisions a
 * host takes on the floor.
 *
 * Every button is a Journey Action (or, for a number, the queue's
 * `IssueTicket`); `JourneyBoardView::panel()` decides which are offered. The
 * visit is re-resolved through `JourneyBoardQuery::find()` on every render, so
 * a uuid from a crafted request can never open a visit outside the viewer's
 * branches or, for a view-own employee, outside their own chair.
 *
 * Checkout is a LINK to the till. This screen never calls Sales, and
 * completing a visit creates no sale (docs/18-SALES.md §33).
 */
final class VisitPanel extends Component
{
    use RunsDeskActions;

    public string $journey = '';

    /** '' or one of: skip, reassign, swap, handoff, note, leave, cancel. */
    public string $form = '';

    /** The stage the open form is about. */
    public string $stage = '';

    public string $reason = '';

    public string $employee = '';

    public string $swapFrom = '';

    public string $swapTo = '';

    public string $noteBody = '';

    public string $noteVisibility = 'internal';

    #[On('open-visit')]
    public function open(string $uuid): void
    {
        $this->resetForm();
        $this->notice = '';
        $this->journey = $uuid;
    }

    public function close(): void
    {
        $this->resetForm();
        $this->journey = '';
    }

    public function showForm(string $form, string $stage = ''): void
    {
        $this->resetForm();
        $this->form = in_array($form, ['skip', 'reassign', 'swap', 'handoff', 'note', 'leave', 'cancel'], true) ? $form : '';
        $this->stage = $stage;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function start(string $stageUuid, TransitionStage $transition): void
    {
        $this->onStage($stageUuid, fn (JourneyStage $s) => $transition($s, StageStatus::InService, $this->viewer()), 'started');
    }

    public function finish(string $stageUuid, TransitionStage $transition): void
    {
        $this->onStage($stageUuid, fn (JourneyStage $s) => $transition($s, StageStatus::Completed, $this->viewer()), 'finished');
    }

    public function skip(TransitionStage $transition): void
    {
        $this->onStage($this->stage, fn (JourneyStage $s) => $transition($s, StageStatus::Skipped, $this->viewer(), ['reason' => $this->reason]), 'skipped');
    }

    public function reassign(ReassignStageEmployee $reassign): void
    {
        $this->onStage($this->stage, fn (JourneyStage $s) => $reassign($s, $this->employee, $this->viewer()), 'reassigned');
    }

    public function swap(SwapStageResource $swap): void
    {
        $this->onStage($this->stage, fn (JourneyStage $s) => $swap($s, $this->swapFrom, $this->swapTo, $this->viewer()), 'swapped');
    }

    public function handoff(HandoffStage $handoff): void
    {
        $this->onStage($this->stage, fn (JourneyStage $s) => $handoff($s, $this->viewer(), null, $this->reason), 'handed_on');
    }

    public function addNote(ManageStageNotes $notes): void
    {
        $this->onStage($this->stage, fn (JourneyStage $s) => $notes->add(
            $s,
            $this->noteBody,
            $this->viewer(),
            NoteVisibility::tryFrom($this->noteVisibility) ?? NoteVisibility::Internal,
        ), 'note_added');
    }

    public function deleteNote(string $stageUuid, string $noteUuid, ManageStageNotes $notes): void
    {
        $this->onStage($stageUuid, function (JourneyStage $s) use ($noteUuid, $notes): void {
            $notes->delete($s, $s->internalNotes()->where('uuid', $noteUuid)->firstOrFail(), $this->viewer());
        }, 'note_deleted');
    }

    public function issueTicket(string $stageUuid, IssueTicket $issue): void
    {
        $this->attempt(function () use ($stageUuid, $issue): void {
            $ticket = $issue(app(JourneyBoardQuery::class)->stage($this->viewer(), $stageUuid), $this->viewer(), ['source' => 'staff']);

            $this->changed(__('manager_visits.notices.ticket', ['number' => $ticket->display_number]));
        });
    }

    public function complete(CompleteJourney $complete): void
    {
        $this->onVisit(fn (ServiceJourney $j) => $complete($j, $this->viewer()), 'completed');
    }

    public function leave(AbortJourney $abort): void
    {
        $this->onVisit(fn (ServiceJourney $j) => $abort($j, $this->viewer(), $this->reason), 'left');
    }

    public function cancelBooking(CancelVisit $cancel): void
    {
        $this->onVisit(function (ServiceJourney $j) use ($cancel): void {
            $appointment = $j->appointment;

            if ($appointment === null) {
                throw JourneyFailed::policy('That service does not belong to a visit.');
            }

            // Ends the visit AND the reservation, through the Actions that own
            // each of them (Journey never writes appointments.status).
            $cancel($appointment, $this->viewer(), $this->reason);
        }, 'cancelled');
    }

    public function render(JourneyBoardQuery $board, JourneyBoardView $view, VisitOptions $options, Entitlements $entitlements): View
    {
        $panel = null;
        $choices = [];

        if ($this->journey !== '') {
            try {
                $row = $board->find($this->viewer(), $this->journey);
                $panel = $view->panel(
                    $row,
                    $this->viewer(),
                    $entitlements->enabled('booking'),
                    $entitlements->enabled('queue_management'),
                );

                if ($this->stage !== '' && in_array($this->form, ['reassign', 'swap'], true)) {
                    $stage = $row->journey?->stages->firstWhere('uuid', $this->stage);

                    if ($stage instanceof JourneyStage) {
                        $stage->setRelation('journey', $row->journey);
                        $choices = $this->form === 'reassign' ? $options->reassignCandidates($stage) : $options->swapOptions($stage);
                    }
                }
            } catch (\Throwable $failure) {
                if (! OperationalFailure::handles($failure)) {
                    throw $failure;
                }

                $this->journey = '';
                $panel = null;
            }
        }

        return view('livewire.center.journey.visit-panel', [
            'panel' => $panel,
            'choices' => $choices,
            'formSubmit' => ['skip' => 'skip', 'reassign' => 'reassign', 'swap' => 'swap', 'handoff' => 'handoff', 'note' => 'addNote'][$this->form] ?? 'cancelForm',
            'canCheckout' => $this->viewer()->hasPermission(Permission::SaleCreate) && $entitlements->enabled('pos'),
            'canViewNotes' => $this->viewer()->hasPermission(Permission::JourneyNoteView),
        ]);
    }

    /**
     * @param  callable(JourneyStage): mixed  $work
     */
    private function onStage(string $stageUuid, callable $work, string $outcome): void
    {
        $this->attempt(function () use ($stageUuid, $work, $outcome): void {
            $work(app(JourneyBoardQuery::class)->stage($this->viewer(), $stageUuid));

            $this->changed(__('manager_visits.notices.'.$outcome));
        });
    }

    /**
     * @param  callable(ServiceJourney): mixed  $work
     */
    private function onVisit(callable $work, string $outcome): void
    {
        $this->attempt(function () use ($work, $outcome): void {
            $row = app(JourneyBoardQuery::class)->find($this->viewer(), $this->journey);

            if ($row->journey === null) {
                throw JourneyFailed::policy('That service does not belong to a visit.');
            }

            $work($row->journey);

            $this->changed(__('manager_visits.notices.'.$outcome));
        });
    }

    private function changed(string $message): void
    {
        $this->resetForm();
        $this->succeeded($message);
        $this->dispatch('visit-changed', message: $message);
    }

    private function resetForm(): void
    {
        $this->reset(['form', 'stage', 'reason', 'employee', 'swapFrom', 'swapTo', 'noteBody', 'noteVisibility']);
        $this->resetErrorBag();
    }
}
