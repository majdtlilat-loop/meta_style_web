<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Calendar;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reads the book for the {@see Calendar} page.
 *
 * Only through {@see CalendarQuery} (scope in the SQL) and
 * {@see AppointmentPresenter} (masking), then decorated by
 * {@see CalendarBoard::row()}. A refusal — a range too wide, a branch out of
 * scope — comes back as a localized sentence and an empty book, never as an
 * error page.
 *
 * @phpstan-import-type CalendarFilters from CalendarQuery
 */
final class DeskData
{
    public function __construct(
        private readonly CalendarQuery $calendar,
        private readonly AppointmentPresenter $presenter,
    ) {}

    /**
     * @param  CalendarFilters  $filters
     * @return array{rows: list<array<string, mixed>>, page: LengthAwarePaginator<int, mixed>|null, counts: array<string, int>, error: string|null}
     */
    public function load(User $viewer, DeskRange $range, array $filters, bool $affected): array
    {
        $rows = [];
        $page = null;
        $counts = array_fill_keys(AppointmentStatus::values(), 0);
        $error = null;

        try {
            if ($affected) {
                $found = $this->calendar->affectedByInactiveEmployees($viewer)->all();
            } elseif ($range->view === 'list') {
                $page = $this->calendar->paginate($range->from, $range->to, $viewer, $filters, 25);
                $found = $page->items();
            } else {
                $found = $this->calendar->forRange($range->from, $range->to, $viewer, $filters)->all();
            }

            foreach ($found as $appointment) {
                $rows[] = CalendarBoard::row($this->presenter->summary($appointment, $viewer));
            }

            if (! $affected) {
                $counts = $this->calendar->statusCounts($range->from, $range->to, $viewer, $filters);
            }
        } catch (BookingFailed|AuthorizationException $e) {
            $error = BookingMessages::for($e);
        }

        return ['rows' => $rows, 'page' => $page, 'counts' => $counts, 'error' => $error];
    }
}
