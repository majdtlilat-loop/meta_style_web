<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Notes\NoteAdvisory;
use App\Kernel\Notes\NoteVisibility;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Booking\Concerns\RunsBookingActions;
use App\Livewire\Center\Booking\Support\AppointmentView;
use App\Modules\Booking\Application\Actions\IssueVerificationCode;
use App\Modules\Booking\Application\Actions\ManageAppointmentNotes;
use App\Modules\Booking\Application\Actions\ReassignAppointmentEmployee;
use App\Modules\Booking\Application\Actions\ReassignAppointmentResource;
use App\Modules\Booking\Application\AppointmentActions;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\BookingOptions;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\VerificationCode;
use App\Modules\ServiceJourney\Application\Actions\CancelVisit;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One booking, in a drawer: what was booked, and everything the viewer may do
 * about it.
 *
 * Resolved on EVERY request through {@see CalendarQuery::find()} — permission,
 * branch scope and own scope — so a uuid typed into a crafted request is not
 * found rather than shown (docs/08 §19). The Appointment model never appears
 * in this class: the finder's result goes straight to the engine or an Action.
 *
 * Buttons come from {@see AppointmentActions} (the engine's own rules), and
 * each Action re-checks everything anyway. Two journey facts are read here
 * because Booking may not know the journey exists (ADR-049): a booking whose
 * visit is RUNNING is finished on the visit board, not here, and cancelling
 * one goes through `CancelVisit`, which ends the visit too.
 */
final class AppointmentPanel extends Component
{
    use RunsBookingActions;

    #[Locked]
    public string $appointment = '';

    /** `detail`, `move`, `cancel`, `reassign` or `room`. */
    public string $mode = 'detail';

    public string $cancelReason = '';

    public string $noteBody = '';

    public string $noteVisibility = 'internal';

    public string $reassignItem = '';

    /** An employee uuid, or empty for "any available". */
    public string $reassignTo = '';

    public string $roomItem = '';

    /** The held room or device to give up, and the one to take instead (uuids). */
    public string $roomFrom = '';

    public string $roomTo = '';

    /** A NEW code, shown on this render and cleared by the next action (docs/24 §11). */
    public string $issuedCode = '';

    public function mount(string $appointment): void
    {
        $this->appointment = $appointment;
    }

    // ------------------------------------------------------------- lifecycle

    public function confirm(BookingEngine $engine): void
    {
        $this->transition($engine, AppointmentStatus::Confirmed, 'confirmed');
    }

    public function complete(BookingEngine $engine): void
    {
        $this->transition($engine, AppointmentStatus::Completed, 'completed');
    }

    public function noShow(BookingEngine $engine): void
    {
        $this->transition($engine, AppointmentStatus::NoShow, 'no_show');
    }

    public function cancel(BookingEngine $engine, CancelVisit $cancelVisit): void
    {
        $this->validate(['cancelReason' => ['nullable', 'string', 'max:190']], [], ['cancelReason' => __('manager_booking.panel.reason')]);
        $reason = trim($this->cancelReason) === '' ? null : trim($this->cancelReason);

        $done = $this->attempt(function () use ($engine, $cancelVisit, $reason): bool {
            $appointment = $this->resolve();

            // A visit already in the building is ended with its booking, or the
            // board would keep a journey for a cancelled appointment.
            if (AppointmentView::visitState((int) $appointment->getKey()) === 'active') {
                $cancelVisit($appointment, $this->viewer(), $reason);
            } else {
                $engine->cancel($appointment, BookingActor::staff($this->viewer()), $reason);
            }

            return true;
        });

        if ($done === true) {
            $this->cancelReason = '';
            $this->changed('cancelled');
        }
    }

    public function checkIn(CheckInAppointment $checkIn): void
    {
        if ($this->attempt(fn () => $checkIn($this->resolve(), $this->viewer())) !== null) {
            $this->changed('checked_in');
        }
    }

    public function issueCode(IssueVerificationCode $issue): void
    {
        $this->issuedCode = '';
        $code = $this->attempt(fn (): string => $issue->forStaff($this->resolve(), $this->viewer()));

        if (is_string($code)) {
            $this->issuedCode = VerificationCode::format($code);
            $this->notice = __('manager_booking.notices.code_issued');
        }
    }

    public function clearCode(): void
    {
        $this->issuedCode = '';
    }

    // ------------------------------------------------------ move & reassign

    public function show(string $mode): void
    {
        $this->issuedCode = '';
        $this->mode = in_array($mode, ['detail', 'move', 'cancel'], true) ? $mode : 'detail';
        $this->resetErrorBag();
    }

    #[On('appointment-moved')]
    public function moved(string $uuid): void
    {
        if ($uuid === $this->appointment) {
            $this->mode = 'detail';
            $this->notice = __('manager_booking.notices.moved');
            $this->error = '';
        }
    }

    #[On('appointment-move-cancelled')]
    public function moveCancelled(): void
    {
        $this->mode = 'detail';
    }

    public function startReassign(string $itemUuid): void
    {
        $this->issuedCode = '';
        $this->reassignItem = $itemUuid;
        $this->reassignTo = '';
        $this->mode = 'reassign';
    }

    public function reassign(ReassignAppointmentEmployee $reassign): void
    {
        $to = $this->reassignTo === '' ? null : $this->reassignTo;

        if ($this->attempt(fn () => $reassign($this->resolve(), $this->reassignItem, $to, $this->viewer())) !== null) {
            $this->reassignItem = '';
            $this->changed('reassigned');
        }
    }

    /** Opens the room form for one service; `$resourceUuid` preselects the room when it holds only one. */
    public function startRoomChange(string $itemUuid, string $resourceUuid = ''): void
    {
        $this->issuedCode = '';
        $this->roomItem = $itemUuid;
        $this->roomFrom = $resourceUuid;
        $this->roomTo = '';
        $this->mode = 'room';
        $this->resetErrorBag();
    }

    public function updatedRoomFrom(): void
    {
        $this->roomTo = '';
    }

    public function changeRoom(ReassignAppointmentResource $change): void
    {
        $this->validate(
            ['roomFrom' => ['required', 'string', 'max:64'], 'roomTo' => ['required', 'string', 'max:64']],
            [],
            ['roomFrom' => __('manager_booking.panel.room_current'), 'roomTo' => __('manager_booking.panel.room_new')],
        );

        if ($this->attempt(fn () => $change($this->resolve(), $this->roomItem, $this->roomFrom, $this->roomTo, $this->viewer())) !== null) {
            $this->roomItem = '';
            $this->changed('room_changed');
        }
    }

    // ------------------------------------------------------------------ notes

    public function addNote(ManageAppointmentNotes $notes): void
    {
        $this->validate([
            'noteBody' => ['required', 'string', 'max:2000'],
            'noteVisibility' => ['required', 'in:internal,manager_only'],
        ], [], ['noteBody' => __('manager_booking.notes.body')]);

        $visibility = NoteVisibility::from($this->noteVisibility);

        if ($this->attempt(fn () => $notes->add($this->resolve(), $this->noteBody, $this->viewer(), $visibility)) !== null) {
            $this->noteBody = '';
            $this->notice = __('manager_booking.notices.note_saved');
        }
    }

    public function deleteNote(string $noteUuid, ManageAppointmentNotes $notes): void
    {
        $done = $this->attempt(function () use ($noteUuid, $notes): bool {
            $appointment = $this->resolve();
            $note = $appointment->internalNotes()->where('uuid', $noteUuid)->firstOrFail();
            $notes->delete($appointment, $note, $this->viewer());

            return true;
        });

        if ($done === true) {
            $this->notice = __('manager_booking.notices.note_deleted');
        }
    }

    public function close(): void
    {
        $this->issuedCode = '';
        $this->dispatch('booking-panel-closed');
    }

    // ----------------------------------------------------------------- render

    public function render(
        AppointmentPresenter $presenter,
        AppointmentActions $actions,
        BookingOptions $options,
        Entitlements $entitlements,
    ): View {
        $viewer = $this->viewer();

        try {
            $appointment = $this->resolve();
        } catch (ModelNotFoundException) {
            return view('livewire.center.booking.appointment-panel', ['booking' => null]);
        }

        $booking = AppointmentView::detail($presenter->detail($appointment, $viewer));
        $can = $actions->for($appointment, $viewer);
        $visit = AppointmentView::visitState((int) $appointment->getKey());

        if ($visit === 'active') {
            // Running: finished on the board, where the journey is (ADR-049).
            $can = array_merge($can, ['complete' => false, 'no_show' => false, 'no_show_later' => false, 'reschedule' => false, 'reassign' => false, 'change_room' => false]);
        }

        $today = BranchClock::localDate(CarbonImmutable::now()->utc(), (string) $booking['timezone']);
        $rooms = $this->mode === 'room' ? $options->roomCandidates($appointment, $this->roomItem) : [];
        $roomOptions = [];

        foreach ($rooms as $room) {
            if ($room['uuid'] === $this->roomFrom) {
                $roomOptions = $room['options'];
            }
        }

        return view('livewire.center.booking.appointment-panel', [
            'booking' => $booking,
            'can' => $can,
            'visit' => $visit,
            'canCheckIn' => $can['writable'] && $visit === 'none' && ! $appointment->isTerminal()
                && $booking['local_date'] === $today && $viewer->hasPermission(Permission::JourneyManage),
            'boardUrl' => $viewer->hasPermission(Permission::JourneyView) || $viewer->hasPermission(Permission::JourneyViewOwn)
                ? $this->link('center.board') : null,
            'candidates' => $this->mode === 'reassign' ? $options->reassignCandidates($appointment, $this->reassignItem) : [],
            'rooms' => $rooms,
            'roomOptions' => $roomOptions,
            'advisory' => NoteAdvisory::text(),
            'bookingLocked' => ! $entitlements->enabled('booking'),
        ]);
    }

    // -------------------------------------------------------------- internals

    /**
     * The appointment, if this viewer may see it — else not found. Typed only
     * by the finder: this surface never names the model (BookingBoundaryTest).
     */
    private function resolve(): mixed
    {
        return app(CalendarQuery::class)->find($this->appointment, $this->viewer());
    }

    /** A Manager link, or none where no center host is bound (a console or test render). */
    private function link(string $route): ?string
    {
        try {
            return Route::has($route) ? route($route) : null;
        } catch (UrlGenerationException) {
            return null;
        }
    }

    private function transition(BookingEngine $engine, AppointmentStatus $target, string $notice): void
    {
        $this->issuedCode = '';

        if ($this->attempt(fn () => $engine->transition($this->resolve(), $target, BookingActor::staff($this->viewer()))) !== null) {
            $this->changed($notice);
        }
    }

    private function changed(string $notice): void
    {
        $this->mode = 'detail';
        $this->notice = __('manager_booking.notices.'.$notice);
        $this->dispatch('appointment-changed', uuid: $this->appointment);
    }
}
