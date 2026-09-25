<?php

declare(strict_types=1);

namespace App\Livewire\Center\Queue;

use App\Livewire\Center\Queue\Concerns\RunsDeskActions;
use App\Modules\Queue\Application\Actions\AbandonQueuedVisit;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CancelTicket;
use App\Modules\Queue\Application\Actions\ChangeTicketPriority;
use App\Modules\Queue\Application\Actions\HoldTicket;
use App\Modules\Queue\Application\Actions\ResumeTicket;
use App\Modules\Queue\Application\Actions\TransferTicket;
use App\Modules\Queue\Application\QueueBoardQuery;
use App\Modules\Queue\Application\QueueBoardView;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\VisitOptions;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One ticket, in a drawer: what happened to it, and the decisions that need a
 * reason or a destination — call to a desk, hold, transfer, priority, cancel,
 * "customer left".
 *
 * A child of the queue page so the page's 5-second poll never wipes a reason
 * somebody is typing. Every button is the Action the API calls; the card's
 * one-press actions stay on the board.
 */
final class TicketPanel extends Component
{
    use RunsDeskActions;

    /** The open ticket's uuid; empty when the drawer is closed. */
    public string $ticket = '';

    /** Which form is open: '', call, hold, transfer, priority, cancel, abandon. */
    public string $form = '';

    public string $point = '';

    public string $department = '';

    public string $reason = '';

    public int $priority = 0;

    #[On('queue-open-ticket')]
    public function open(string $uuid): void
    {
        $this->resetForm();
        $this->notice = '';
        $this->ticket = $uuid;
    }

    public function close(): void
    {
        $this->resetForm();
        $this->ticket = '';
    }

    public function showForm(string $form): void
    {
        $this->resetForm();
        $this->form = in_array($form, ['call', 'hold', 'transfer', 'priority', 'cancel', 'abandon'], true) ? $form : '';

        if ($this->form === 'priority') {
            $this->attempt(function (): void {
                $this->priority = $this->find()->priority;
            });
        }
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function callTo(CallTicket $call): void
    {
        $this->act(fn (QueueTicket $t): QueueTicket => $call($t, $this->viewer(), $this->point === '' ? null : $this->point), 'called');
    }

    public function hold(HoldTicket $hold): void
    {
        $this->act(fn (QueueTicket $t): QueueTicket => $hold($t, $this->viewer(), false, $this->reason), 'held');
    }

    public function skip(HoldTicket $hold): void
    {
        $this->act(fn (QueueTicket $t): QueueTicket => $hold($t, $this->viewer(), true), 'skipped');
    }

    public function resume(ResumeTicket $resume): void
    {
        $this->act(fn (QueueTicket $t): QueueTicket => $resume($t, $this->viewer()), 'resumed');
    }

    public function transfer(TransferTicket $transfer): void
    {
        $this->act(fn (QueueTicket $t): QueueTicket => $transfer(
            $t,
            $this->viewer(),
            $this->point === '' ? null : $this->point,
            $this->department === '' ? null : $this->department,
            $this->reason,
        ), 'transferred');
    }

    public function changePriority(ChangeTicketPriority $change): void
    {
        $this->act(fn (QueueTicket $t): QueueTicket => $change($t, max(0, min(255, $this->priority)), $this->viewer(), $this->reason), 'prioritised');
    }

    public function cancelTicket(CancelTicket $cancel): void
    {
        $this->act(fn (QueueTicket $t): QueueTicket => $cancel($t, $this->viewer(), $this->reason), 'cancelled');
    }

    public function abandon(AbandonQueuedVisit $abandon): void
    {
        $this->act(function (QueueTicket $t) use ($abandon): QueueTicket {
            $journey = $t->journey;

            if (! $journey instanceof ServiceJourney) {
                throw QueueFailed::policy('That ticket is not attached to a service.');
            }

            $abandon($journey, $this->viewer(), $this->reason);

            return $t->refresh();
        }, 'abandoned');
    }

    public function render(QueueBoardQuery $board, QueueBoardView $view, VisitOptions $options): View
    {
        $detail = null;
        $points = [];

        if ($this->ticket !== '') {
            try {
                $ticket = $board->find($this->viewer(), $this->ticket, true);
                $detail = $view->detail($ticket, $this->viewer());
                $points = array_map(static fn (QueueServicePoint $p): array => [
                    'uuid' => $p->uuid,
                    'label' => $p->display_code.' · '.$p->name->get(),
                ], array_values(array_filter(
                    $board->servicePoints($this->viewer()),
                    static fn (QueueServicePoint $p): bool => (int) $p->branch_id === (int) $ticket->branch_id,
                )));
            } catch (\Throwable $failure) {
                if (! OperationalFailure::handles($failure)) {
                    throw $failure;
                }

                $this->ticket = '';
            }
        }

        return view('livewire.center.queue.ticket-panel', [
            'detail' => $detail,
            // The form's Enter key. Cancel and "customer left" have none: they
            // go only through their confirmed button.
            'submit' => ['call' => 'callTo', 'hold' => 'hold', 'transfer' => 'transfer', 'priority' => 'changePriority'][$this->form] ?? null,
            'points' => $points,
            'departments' => $detail === null ? [] : $options->departments(),
        ]);
    }

    /**
     * @param  callable(QueueTicket): QueueTicket  $work
     */
    private function act(callable $work, string $outcome): void
    {
        $this->attempt(function () use ($work, $outcome): void {
            $ticket = $work($this->find());

            $this->resetForm();
            $message = __('manager_queue.notices.'.$outcome, ['number' => $ticket->display_number]);
            $this->succeeded($message);

            // The board re-reads the database; the drawer keeps its own copy.
            $this->dispatch('queue-changed', message: $message);
        });
    }

    private function find(): QueueTicket
    {
        return app(QueueBoardQuery::class)->find($this->viewer(), $this->ticket);
    }

    private function resetForm(): void
    {
        $this->reset(['form', 'point', 'department', 'reason', 'priority']);
        $this->resetErrorBag();
    }
}
