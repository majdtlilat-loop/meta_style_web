<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking;

use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Booking\Concerns\RunsBookingActions;
use App\Livewire\Center\Booking\Support\BookingFormat;
use App\Livewire\Center\Booking\Support\SlotList;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Moving a booking: pick a day, pick one of the times the engine offers, move.
 *
 * The times come from `AvailabilityQuery::forMove()` — the visit laid out from
 * its STORED items, ignoring its own current place — so a desk is offered the
 * moves the reschedule will actually accept, on any day, including a shift of
 * half an hour (docs/15-BOOKING.md §4). The move itself is
 * `BookingEngine::reschedule()`, which re-checks everything under the branch
 * lock; an offered time can still be refused there (§7).
 */
final class RescheduleForm extends Component
{
    use RunsBookingActions;

    #[Locked]
    public string $appointment = '';

    public string $date = '';

    /** @var list<array{starts_at: string, time: string, staff: string, current: bool}> */
    public array $slots = [];

    public string $slot = '';

    public bool $searched = false;

    public function mount(string $appointment): void
    {
        $this->appointment = $appointment;

        $booking = $this->attempt(fn () => app(CalendarQuery::class)->find($appointment, $this->viewer()));

        if ($booking === null) {
            return;
        }

        $today = $this->todayFor($booking->booked_timezone);
        $this->date = max($booking->localDate(), $today);

        $this->find(app(BookingEngine::class), app(SlotList::class));
    }

    public function updatedDate(): void
    {
        $this->find(app(BookingEngine::class), app(SlotList::class));
    }

    public function shift(int $days, BookingEngine $engine, SlotList $list): void
    {
        $this->date = CarbonImmutable::parse($this->date)->addDays($days)->format('Y-m-d');

        $this->find($engine, $list);
    }

    public function find(BookingEngine $engine, SlotList $list): void
    {
        $this->slots = [];
        $this->slot = '';

        $found = $this->attempt(function () use ($engine, $list): array {
            $booking = app(CalendarQuery::class)->find($this->appointment, $this->viewer());
            $today = $this->todayFor($booking->booked_timezone);

            if (CarbonImmutable::hasFormat($this->date, 'Y-m-d') === false || $this->date < $today) {
                $this->date = $today;
            }

            return $list->present(
                $engine->availability(AvailabilityQuery::forMove((string) $booking->branch?->uuid, $this->appointment, $this->date)),
                $booking->starts_at->utc()->toIso8601String(),
            );
        });

        $this->searched = true;

        if (is_array($found)) {
            $this->slots = $found;
        }
    }

    public function pick(string $startsAt): void
    {
        foreach ($this->slots as $slot) {
            if ($slot['starts_at'] === $startsAt && ! $slot['current']) {
                $this->slot = $startsAt;
            }
        }
    }

    public function move(BookingEngine $engine): void
    {
        if ($this->slot === '') {
            $this->error = __('manager_booking.composer.pick_time');

            return;
        }

        $moved = $this->attempt(fn () => $engine->reschedule(
            app(CalendarQuery::class)->find($this->appointment, $this->viewer()),
            CarbonImmutable::parse($this->slot)->utc(),
            BookingActor::staff($this->viewer()),
        ));

        if ($moved === null) {
            // Taken a moment ago: say so, and show what is left.
            $refusal = $this->error;
            $this->find($engine, app(SlotList::class));
            $this->error = $refusal;

            return;
        }

        $this->dispatch('appointment-moved', uuid: $this->appointment);
        $this->dispatch('appointment-changed', uuid: $this->appointment);
    }

    public function cancel(): void
    {
        $this->dispatch('appointment-move-cancelled');
    }

    public function render(): View
    {
        return view('livewire.center.booking.reschedule-form', [
            'periods' => BookingFormat::periods($this->slots),
            'dateLabel' => $this->date !== '' ? BookingFormat::dateLong($this->date) : '',
        ]);
    }

    private function todayFor(string $timezone): string
    {
        return BranchClock::localDate(CarbonImmutable::now()->utc(), $timezone);
    }
}
