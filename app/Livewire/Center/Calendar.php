<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Booking\Support\BookingFormat;
use App\Livewire\Center\Booking\Support\CalendarBoard;
use App\Livewire\Center\Booking\Support\DeskData;
use App\Livewire\Center\Booking\Support\DeskRange;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\BookingOptions;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The bookings desk: day, week and list, and the drawers that act on them.
 *
 * ## A page, three children
 *
 * This component owns only what the URL says — the date, the view, the
 * filters — and draws the book. Making a booking is
 * {@see Booking\Composer}; reading and acting on one is
 * {@see Booking\AppointmentPanel} (which hosts {@see Booking\RescheduleForm}).
 * They talk through events carrying a uuid and nothing else — a one-time
 * verification code never leaves the composer that minted it
 * (docs/24-BOOKING-VERIFICATION.md §11).
 *
 * ## Nothing here decides anything
 *
 * Every appointment is read through {@see CalendarQuery} (permission, branch
 * scope and own scope in the SQL) and presented by {@see AppointmentPresenter}
 * (masking), via {@see DeskData}. Every change goes through the Booking Engine
 * or a Booking Action in a child. {@see CalendarBoard} only does geometry
 * (docs/15-BOOKING.md §1). This class never names the Appointment model.
 *
 * ## After a downgrade
 *
 * Reading survives, writing does not (§14): a center that no longer owns
 * `booking` but has a history keeps this page read-only with a compact notice;
 * one that never had a booking sees the upgrade page and loads nothing.
 */
#[Layout('components.layouts.app')]
final class Calendar extends Component
{
    use RequiresFeature;
    use WithPagination;

    private const VIEWS = ['day', 'week', 'list'];

    #[Url]
    public string $date = '';

    /** `day`, `week` or `list`. */
    #[Url]
    public string $view = 'day';

    /** Day view lanes: `team` or `rooms`. */
    #[Url]
    public string $lanes = 'team';

    /** A branch uuid, or `all`. */
    #[Url(as: 'branch')]
    public string $branchUuid = '';

    #[Url(as: 'employee')]
    public string $employeeUuid = '';

    #[Url(as: 'service')]
    public string $serviceUuid = '';

    #[Url(as: 'status')]
    public string $statusFilter = '';

    /** A booking reference or a customer. */
    #[Url(as: 'q')]
    public string $search = '';

    /** The list view's last day. */
    #[Url(as: 'to')]
    public string $until = '';

    /** Future bookings whose team member is no longer active (§17). */
    #[Url]
    public bool $affected = false;

    /** The open appointment, so a link can land on it. */
    #[Url(as: 'appointment')]
    public ?string $viewing = null;

    /** The new-booking drawer, so "New booking" elsewhere can open it. */
    #[Url(as: 'new')]
    public bool $composing = false;

    /** Remounts the composer fresh each time it opens. */
    public int $composerRun = 0;

    /** @var list<array{uuid: string, name: string, timezone: string}>|null per request, never serialised */
    private ?array $branchList = null;

    public function mount(): void
    {
        $user = $this->viewer();

        abort_unless($user->hasPermission(Permission::AppointmentView) || $user->hasPermission(Permission::AppointmentViewOwn), 403);

        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'day';
        $this->lanes = $this->lanes === 'rooms' ? 'rooms' : 'team';
        $this->keepBranchInScope();
        $this->date = DeskRange::validDate($this->date) ?? $this->todayLocal();
        $this->until = DeskRange::listEnd($this->view, $this->date, DeskRange::validDate($this->until) ?? '');
        $this->affected = $this->affected && $user->hasPermission(Permission::AppointmentView);
    }

    // ------------------------------------------------------------ navigation

    public function move(int $steps): void
    {
        $days = $steps * $this->range()->step();

        $this->date = CarbonImmutable::parse($this->date)->addDays($days)->format('Y-m-d');

        if ($this->view === 'list') {
            $this->until = CarbonImmutable::parse($this->until)->addDays($days)->format('Y-m-d');
        }

        $this->resetPage();
    }

    public function goToday(): void
    {
        $this->date = $this->todayLocal();
        $this->until = DeskRange::listEnd($this->view, $this->date, '');
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'day';
        $this->until = DeskRange::listEnd($this->view, $this->date, $this->until);
        $this->resetPage();
    }

    /** From a week column's heading to that day. */
    public function openDay(string $date): void
    {
        $this->date = DeskRange::validDate($date) ?? $this->date;
        $this->view = 'day';
        $this->resetPage();
    }

    public function setLanes(string $lanes): void
    {
        $this->lanes = $lanes === 'rooms' ? 'rooms' : 'team';
    }

    public function setStatus(string $status): void
    {
        $this->statusFilter = AppointmentStatus::tryFrom($status) instanceof AppointmentStatus ? $status : '';
        $this->resetPage();
    }

    public function updated(string $property): void
    {
        if ($property === 'date' || $property === 'until') {
            $this->date = DeskRange::validDate($this->date) ?? $this->todayLocal();
            $this->until = DeskRange::listEnd($this->view, $this->date, DeskRange::validDate($this->until) ?? '');
        }

        if ($property === 'branchUuid') {
            $this->keepBranchInScope();
            // Another branch has another team and another menu.
            $this->employeeUuid = '';
            $this->serviceUuid = '';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->employeeUuid = '';
        $this->serviceUuid = '';
        $this->statusFilter = '';
        $this->search = '';
        $this->affected = false;
        $this->resetPage();
    }

    // --------------------------------------------------------------- drawers

    public function startBooking(): void
    {
        $this->viewing = null;
        $this->composing = true;
        $this->composerRun++;
    }

    #[On('booking-composer-closed')]
    public function closeComposer(): void
    {
        $this->composing = false;
    }

    #[On('open-appointment')]
    public function open(string $uuid): void
    {
        $this->composing = false;
        $this->viewing = $uuid;
    }

    #[On('booking-panel-closed')]
    public function closePanel(): void
    {
        $this->viewing = null;
    }

    /** A child changed the book; this render redraws it. Also the poll target. */
    #[On('booking-created')]
    #[On('appointment-changed')]
    public function refreshBoard(): void {}

    // ------------------------------------------------------------------ render

    public function render(CalendarQuery $calendar, DeskData $data, BookingOptions $options, Entitlements $entitlements): View
    {
        $user = $this->viewer();

        // Never owned `booking` and never booked anything: the upgrade page,
        // and no data is loaded (§14).
        $offer = $this->lockedFeature('booking');

        if ($offer !== null && ! $calendar->hasHistory()) {
            return view('livewire.center.feature-locked', ['offer' => $offer])->title(__('manager_booking.title'));
        }

        $branch = in_array($this->branchUuid, ['', 'all'], true) ? null : $this->branchUuid;
        $broad = $user->hasPermission(Permission::AppointmentView);
        $affected = $this->affected && $broad;
        $range = $this->range();

        $book = $data->load($user, $range, [
            'branch' => $branch,
            'employee' => $this->employeeUuid !== '' ? $this->employeeUuid : null,
            'service' => $this->serviceUuid !== '' ? $this->serviceUuid : null,
            'status' => $this->statusFilter !== '' ? $this->statusFilter : null,
            'search' => trim($this->search) !== '' ? $this->search : null,
            'with_resources' => $this->view === 'day' && $this->lanes === 'rooms',
        ], $affected);

        $team = $options->team($user, $branch);
        $rooms = $options->rooms($user, $branch);
        $today = $this->todayLocal();

        return view('livewire.center.calendar', [
            'offer' => $offer,
            'readOnly' => $offer !== null || ! $entitlements->enabled('booking'),
            'branches' => $this->branches(),
            'team' => $team,
            'services' => $this->serviceOptions($options, $user, $branch),
            'hasRooms' => $rooms !== [],
            'rows' => $book['rows'],
            'page' => $book['page'],
            'board' => $affected || $this->view !== 'day' ? null : CalendarBoard::day(
                $book['rows'],
                $this->lanes === 'rooms' ? $rooms : $team,
                $branch !== null ? $options->openHours($branch, $this->date, $user) : [],
                $this->date,
                $this->lanes === 'rooms' ? 'rooms' : 'team',
                ! $broad || $this->employeeUuid !== '',
                $this->date === $today ? $this->nowMinute() : null,
            ),
            'week' => $affected || $this->view !== 'week' ? [] : CalendarBoard::week($range->days(), $book['rows'], $today),
            'total' => array_sum($book['counts']),
            'statusOptions' => array_map(static fn (string $status): array => [
                'value' => $status,
                'label' => BookingFormat::status($status),
                'count' => $book['counts'][$status] ?? 0,
            ], AppointmentStatus::values()),
            'error' => $book['error'],
            'canCreate' => $entitlements->enabled('booking') && $user->hasPermission(Permission::AppointmentCreate),
            'canSeeAffected' => $broad,
            'canSearchContact' => $user->hasPermission(Permission::CustomerContactView),
            'hasFilters' => $this->employeeUuid !== '' || $this->serviceUuid !== '' || $this->statusFilter !== '' || trim($this->search) !== '' || $affected,
            'heading' => $range->heading(),
            'isToday' => $this->date === $today,
            'composerBranch' => $branch ?? ($this->branches()[0]['uuid'] ?? ''),
        ])->title(__('manager_booking.title'));
    }

    // -------------------------------------------------------------- internals

    private function range(): DeskRange
    {
        return new DeskRange($this->view, $this->date, $this->until);
    }

    /**
     * The service filter's choices: the chosen branch's menu, or each
     * in-scope branch's menu once when the desk shows all of them.
     *
     * @return list<array{uuid: string, name: string}>
     */
    private function serviceOptions(BookingOptions $options, User $user, ?string $branch): array
    {
        $found = [];

        foreach ($branch !== null ? [$branch] : array_column($this->branches(), 'uuid') as $uuid) {
            foreach ($options->services($uuid, $user) as $service) {
                $found[$service['uuid']] ??= ['uuid' => $service['uuid'], 'name' => $service['name']];
            }
        }

        return array_values($found);
    }

    /**
     * The first branch in scope unless a branch in scope (or "all", for a
     * viewer with more than one) was asked for: a day mixing two branches'
     * schedules is unreadable, and reception works at one desk.
     */
    private function keepBranchInScope(): void
    {
        $uuids = array_column($this->branches(), 'uuid');

        if (($this->branchUuid === 'all' && count($uuids) > 1) || in_array($this->branchUuid, $uuids, true)) {
            return;
        }

        $this->branchUuid = $uuids[0] ?? '';
    }

    /**
     * Branches in the viewer's scope, read once per request.
     *
     * @return list<array{uuid: string, name: string, timezone: string}>
     */
    private function branches(): array
    {
        return $this->branchList ??= app(BookingOptions::class)->branches($this->viewer());
    }

    /** Today where the branch is — never the server's day (docs/15 §5). */
    private function todayLocal(): string
    {
        return BranchClock::localDate(CarbonImmutable::now()->utc(), $this->timezone());
    }

    private function nowMinute(): int
    {
        $local = BranchClock::toLocal(CarbonImmutable::now()->utc(), $this->timezone());

        return $local->hour * 60 + $local->minute;
    }

    private function timezone(): string
    {
        foreach ($this->branches() as $branch) {
            if (in_array($this->branchUuid, ['', 'all'], true) || $branch['uuid'] === $this->branchUuid) {
                return $branch['timezone'];
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
