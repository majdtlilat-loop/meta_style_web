<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Kernel\Authorization\AppointmentScope;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Application\AppointmentScopeResolver;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\ServiceJourney\Domain\Enums\JourneySource;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Today's floor, as the desk sees it.
 *
 * ## Not the queue display
 *
 * A STAFF working board: who is expected, who is here, who is being served, who
 * is done. No ticket numbers, no calling, no TV output, no announcements — all
 * of that is Phase 8, built on top of these tables
 * (docs/13-ROADMAP.md Phase 7 §§32, 44).
 *
 * ## Driven by APPOINTMENTS, joined to journeys
 *
 * The most important column is "booked but not arrived", and those rows have no
 * journey at all. So the query starts from the day's appointments and pairs
 * each with its operational record if one exists.
 *
 * Two queries rather than one relation, because Booking must not depend on
 * ServiceJourney (§55). {@see BoardRow} carries the pair.
 *
 * ## Bounded to one branch-local day
 *
 * "Today at Karrada" is a local question, converted once to a UTC window and
 * then an indexed range scan on `(branch_id, starts_at)`. An unbounded board is
 * a board that eventually loads a year of visits into a browser (§32).
 */
final class JourneyBoardQuery
{
    public function __construct(private readonly AppointmentScopeResolver $scopes) {}

    /**
     * One branch-local day of visits.
     *
     * @param  array{branch?: string|null, department?: string|null, employee?: string|null, group?: string|null}  $filters
     * @return list<BoardRow>
     *
     * @throws AuthorizationException
     */
    public function forDay(User $viewer, ?string $date = null, array $filters = []): array
    {
        $scope = $this->scopes->forJourneyViewer($viewer);

        if ($scope->isDenied()) {
            throw new AuthorizationException('You may not view the visit board.');
        }

        $branches = $this->branchesInScope($viewer, $filters['branch'] ?? null);

        if ($branches->isEmpty()) {
            return [];
        }

        $query = Appointment::query()
            ->with([
                'customer',
                'items.service',
                'items.employee',
            ])
            ->whereIn('branch_id', $branches->pluck('id')->all())
            /*
             * Still EXPECTED (booked or confirmed), or already a VISIT.
             *
             * `blocking()` alone dropped every booked visit the moment it
             * finished: CompleteJourney moves the appointment to `completed`
             * and CancelVisit to `cancelled`, so the Completed and Abandoned
             * columns only ever showed walk-ins. A visit that happened stays
             * on the day it happened; a booking cancelled before anybody
             * arrived (no journey) still leaves the board.
             */
            ->where(function (Builder $visible): void {
                $visible->blocking()->orWhereIn(
                    'id',
                    DB::connection('tenant')->table('service_journeys')
                        ->whereNotNull('appointment_id')
                        ->select('appointment_id'),
                );
            })
            ->orderBy('starts_at')
            ->orderBy('id');

        $this->applyDay($query, $branches, $date);
        $this->applyScope($query, $scope);
        $this->applyFilters($query, $filters);

        /** @var Collection<int, Appointment> $appointments */
        $appointments = $query->get();

        $journeys = $appointments->isEmpty() ? [] : $this->journeysFor($appointments);

        $rows = [];

        foreach ($appointments as $appointment) {
            $rows[] = new BoardRow($appointment, $journeys[(int) $appointment->getKey()] ?? null);
        }

        /*
         * Walk-ins are a SECOND source, not a third query shape.
         *
         * They cannot be found by starting from appointments, because they have
         * none — which is exactly why `service_journeys` grew its own
         * `(branch_id, status, arrived_at)` index. One more bounded query for
         * the whole board (docs/16-JOURNEY-RESOURCES.md §22).
         */
        foreach ($this->walkIns($branches, $date, $scope, $filters) as $journey) {
            $rows[] = new BoardRow(null, $journey);
        }

        // A 10:00 booking and somebody who walked in at 10:05 belong in the
        // order the desk experienced them, not in two separate blocks.
        usort($rows, static fn (BoardRow $a, BoardRow $b): int => ($a->sortsAt()?->getTimestamp() ?? 0)
            <=> ($b->sortsAt()?->getTimestamp() ?? 0));

        return $this->applyGroupFilter($rows, $filters['group'] ?? null);
    }

    /**
     * One visit in full, for the detail screen.
     *
     * @throws AuthorizationException
     */
    public function detail(ServiceJourney $journey, User $viewer): BoardRow
    {
        $scope = $this->scopes->forJourneyViewer($viewer);

        if ($scope->isDenied()) {
            throw new AuthorizationException('You may not view visits.');
        }

        $appointment = $journey->appointment;

        if (! $viewer->canAccessBranch($journey->branchId())) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        if ($scope->isLimitedToOwn() && ! $this->isOwn($journey, $appointment, (int) $scope->employeeId)) {
            // A record in another scope is "not found" in spirit, but this one
            // was addressed by uuid and the viewer is authenticated staff, so
            // refusing plainly is the honest answer.
            throw new AuthorizationException('That visit is not yours.');
        }

        $appointment?->load(['customer', 'items.service', 'items.employee']);

        $journey->load([
            'stages.item.employee',
            'stages.employee',
            'stages.department',
            'stages.resources.resource',
            'handoffs.fromEmployee',
            'handoffs.toEmployee',
            'handoffs.fromStage.item',
            'handoffs.toStage.item',
            'handoffs.fromDepartment',
            'handoffs.toDepartment',
        ]);

        // A walk-in's customer is on the journey; a booked visit's is on the
        // appointment, and loading it twice would be a wasted query.
        if ($appointment === null) {
            $journey->load('customer');
        }

        return new BoardRow($appointment, $journey);
    }

    /**
     * One visit by uuid, scope-checked, loaded for the detail panel.
     *
     * The ONE way a staff screen turns a uuid it was sent into a visit, so a
     * crafted request can never open a visit in a branch — or, for a view-own
     * employee, a chair — the viewer does not work in.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<ServiceJourney>
     */
    public function find(User $viewer, string $journeyUuid): BoardRow
    {
        $journey = ServiceJourney::query()
            ->with('appointment')
            ->where('uuid', $journeyUuid)
            ->firstOrFail();

        return $this->detail($journey, $viewer);
    }

    /**
     * One stage by uuid, for an Action — after the same scope check.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<ServiceJourney>
     */
    public function stage(User $viewer, string $stageUuid): JourneyStage
    {
        $stage = JourneyStage::query()
            ->with(['journey.appointment', 'item'])
            ->where('uuid', $stageUuid)
            ->firstOrFail();

        $this->detail($stage->journey, $viewer);

        return $stage;
    }

    /**
     * A booking on the board, for checking the customer in.
     *
     * Branch scope here; the Action checks the permission and the branch again
     * under its own rules. Returned untyped to callers that must not name the
     * Booking model (BookingBoundaryTest).
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<Appointment>
     */
    public function appointment(User $viewer, string $appointmentUuid): Appointment
    {
        $appointment = Appointment::query()->where('uuid', $appointmentUuid)->firstOrFail();

        if (! $viewer->canAccessBranch((int) $appointment->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }

        return $appointment;
    }

    /**
     * Has this center anything to read back on the board — a visit or a
     * booking? Decides whether a center without `booking` sees its read-only
     * history or the upgrade page.
     */
    public function hasHistory(): bool
    {
        return ServiceJourney::query()->exists() || Appointment::query()->exists();
    }

    /**
     * The operational half, keyed by appointment id.
     *
     * ONE query for the whole board. Everything a row renders — stages, who is
     * performing them, which room they are in — is eager-loaded here, because a
     * board with three hundred visits and a lazy relation is three hundred
     * queries (§40).
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, ServiceJourney>
     */
    private function journeysFor(Collection $appointments): array
    {
        $journeys = ServiceJourney::query()
            ->with([
                // `stages.item.employee`: the card shows who was BOOKED beside
                // who is doing it, and a walk-in stage (no item) reads its own
                // service snapshot. Lazy here is a query per stage, and an
                // exception outside production (preventLazyLoading).
                'stages.item.employee',
                'stages.employee',
                'stages.department',
                'stages.resources.resource',
            ])
            ->whereIn('appointment_id', $appointments->pluck('id')->all())
            ->get();

        $map = [];

        foreach ($journeys as $journey) {
            $map[(int) $journey->appointment_id] = $journey;
        }

        return $map;
    }

    /**
     * The UTC window covering one local day across possibly several timezones.
     *
     * A center with a branch in Baghdad and one in Istanbul has two different
     * "todays". The widest covering window is used, which is the same approach
     * the Phase 6 calendar takes.
     *
     * @param  Builder<Appointment>  $query
     * @param  Collection<int, Branch>  $branches
     */
    private function applyDay(Builder $query, Collection $branches, ?string $date): void
    {
        $window = $this->dayWindow($branches, $date);

        if ($window === null) {
            return;
        }

        $query->startingBetween($window[0], $window[1]);
    }

    /**
     * The UTC window covering one local day, or null when there are no branches.
     *
     * Shared by the appointment half of the board and the walk-in half, so the
     * two cannot drift into showing slightly different days.
     *
     * @param  Collection<int, Branch>  $branches
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function dayWindow(Collection $branches, ?string $date): ?array
    {
        $starts = [];
        $ends = [];

        foreach ($branches as $branch) {
            $local = $date ?? BranchClock::localDate(CarbonImmutable::now()->utc(), $branch->timezone);

            $starts[] = BranchClock::toUtcOrShift($local, 0, $branch->timezone);
            $ends[] = BranchClock::toUtcOrShift($local, 24 * 60, $branch->timezone);
        }

        if ($starts === []) {
            return null;
        }

        return [min($starts), max($ends)];
    }

    /**
     * The day's walk-in visits — the ones no appointment can lead to.
     *
     * Half-open on arrival, never `whereBetween`: a visit that arrived exactly
     * at midnight belongs to the day that is starting, and the day that is
     * ending must not claim it too (CLAUDE.md).
     *
     * @param  Collection<int, Branch>  $branches
     * @param  array{branch?: string|null, department?: string|null, employee?: string|null, group?: string|null}  $filters
     * @return list<ServiceJourney>
     */
    private function walkIns(
        Collection $branches,
        ?string $date,
        AppointmentScope $scope,
        array $filters,
    ): array {
        $window = $this->dayWindow($branches, $date);

        if ($window === null) {
            return [];
        }

        $query = ServiceJourney::query()
            ->with([
                'customer',
                'stages.item.employee',
                'stages.employee',
                'stages.department',
                'stages.resources.resource',
            ])
            ->where('source', JourneySource::WalkIn->value)
            ->whereIn('branch_id', $branches->pluck('id')->all())
            ->where('arrived_at', '>=', $window[0])
            ->where('arrived_at', '<', $window[1])
            ->orderBy('arrived_at')
            ->orderBy('id');

        /*
         * "Own" for a walk-in can only mean the ACTUAL assignment: there is no
         * booked employee to compare against. The same rule the booked half
         * applies, with one of its two halves absent (§30).
         */
        if ($scope->isLimitedToOwn()) {
            $query->whereHas('stages', fn (Builder $q) => $q->where('employee_id', $scope->employeeId));
        }

        $employeeId = $this->employeeIdFrom($filters);

        if ($employeeId !== null) {
            $query->whereHas('stages', fn (Builder $q) => $q->where('employee_id', $employeeId));
        }

        $departmentId = $this->departmentIdFrom($filters);

        if ($departmentId !== null) {
            $query->whereHas('stages', fn (Builder $q) => $q->where('department_id', $departmentId));
        }

        /** @var list<ServiceJourney> $journeys */
        $journeys = $query->get()->all();

        return $journeys;
    }

    /**
     * @param  array{branch?: string|null, department?: string|null, employee?: string|null, group?: string|null}  $filters
     */
    private function employeeIdFrom(array $filters): ?int
    {
        $employee = $filters['employee'] ?? null;

        if (! is_string($employee) || $employee === '') {
            return null;
        }

        $id = DB::connection('tenant')->table('employees')->where('uuid', $employee)->value('id');

        // An unknown employee matches NOTHING rather than everything: the honest
        // answer, and it cannot widen what the caller sees.
        return $id === null ? -1 : (int) $id;
    }

    /**
     * @param  array{branch?: string|null, department?: string|null, employee?: string|null, group?: string|null}  $filters
     */
    private function departmentIdFrom(array $filters): ?int
    {
        $department = $filters['department'] ?? null;

        if (! is_string($department) || $department === '') {
            return null;
        }

        $id = DB::connection('tenant')->table('departments')->where('uuid', $department)->value('id');

        return $id === null ? -1 : (int) $id;
    }

    /**
     * Narrows to the viewer's own work — planned OR actual.
     *
     * A stylist handed a customer mid-shift is not on the booking any more: the
     * item still names whoever was booked. A scope that looked only at items
     * would hide the visit they are performing right now (§§18, 30).
     *
     * @param  Builder<Appointment>  $query
     */
    private function applyScope(Builder $query, AppointmentScope $scope): void
    {
        if (! $scope->isLimitedToOwn()) {
            return;
        }

        $stageAppointments = $this->appointmentIdsWithStagesFor((int) $scope->employeeId);

        $query->where(function (Builder $outer) use ($scope, $stageAppointments): void {
            $outer->whereHas('items', fn (Builder $q) => $q->where('employee_id', $scope->employeeId));

            if ($stageAppointments !== []) {
                $outer->orWhereIn('id', $stageAppointments);
            }
        });
    }

    /**
     * Appointment ids whose JOURNEY has a stage this employee is performing.
     *
     * A plain query builder read across the two Journey tables rather than an
     * Eloquent relation from Appointment, which would be the boundary violation
     * this class exists to avoid (§55).
     *
     * @return list<int>
     */
    private function appointmentIdsWithStagesFor(int $employeeId): array
    {
        /** @var list<int> $ids */
        $ids = DB::connection('tenant')
            ->table('journey_stages')
            ->join('service_journeys', 'service_journeys.id', '=', 'journey_stages.service_journey_id')
            ->where('journey_stages.employee_id', $employeeId)
            ->distinct()
            ->pluck('service_journeys.appointment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  array{branch?: string|null, department?: string|null, employee?: string|null, group?: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $employeeId = $this->employeeIdFrom($filters);

        if ($employeeId !== null) {
            $stageAppointments = $this->appointmentIdsWithStagesFor($employeeId);

            $query->where(function (Builder $outer) use ($employeeId, $stageAppointments): void {
                $outer->whereHas('items', fn (Builder $q) => $q->where('employee_id', $employeeId));

                if ($stageAppointments !== []) {
                    $outer->orWhereIn('id', $stageAppointments);
                }
            });
        }

        $departmentId = $this->departmentIdFrom($filters);

        if ($departmentId !== null) {
            $ids = DB::connection('tenant')
                ->table('journey_stages')
                ->join('service_journeys', 'service_journeys.id', '=', 'journey_stages.service_journey_id')
                ->where('journey_stages.department_id', $departmentId)
                ->whereNotNull('service_journeys.appointment_id')
                ->distinct()
                ->pluck('service_journeys.appointment_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $query->whereIn('id', $ids === [] ? [-1] : $ids);
        }
    }

    private function isOwn(ServiceJourney $journey, ?Appointment $appointment, int $employeeId): bool
    {
        foreach ($journey->stages as $stage) {
            if ((int) $stage->employee_id === $employeeId) {
                return true;
            }
        }

        if ($appointment === null) {
            // A walk-in has no booked half, so the stages above are the whole
            // question — and they are the ACTUAL assignment, which is the more
            // honest one anyway.
            return false;
        }

        foreach ($appointment->items as $item) {
            if ((int) $item->employee_id === $employeeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<BoardRow>  $rows
     * @return list<BoardRow>
     */
    private function applyGroupFilter(array $rows, ?string $group): array
    {
        if (! is_string($group) || $group === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static fn (BoardRow $row): bool => $row->group() === $group));
    }

    /**
     * @return Collection<int, Branch>
     *
     * @throws AuthorizationException
     */
    private function branchesInScope(User $viewer, ?string $branchUuid): Collection
    {
        $query = Branch::query()->active();

        $viewer->branchScope()->applyTo($query, 'id');

        if (is_string($branchUuid) && $branchUuid !== '') {
            $query->where('uuid', $branchUuid);
        }

        /** @var Collection<int, Branch> $branches */
        $branches = $query->get();

        // A named branch that is out of scope is refused rather than silently
        // returning an empty board — "you may not" and "there is nothing" are
        // different answers at a desk.
        if (is_string($branchUuid) && $branchUuid !== '' && $branches->isEmpty()) {
            throw new AuthorizationException('You may not view that branch.');
        }

        return $branches;
    }
}
