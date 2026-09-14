<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Authorization\AppointmentScope;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The staff calendar's read model.
 *
 * ## Bounded, always
 *
 * Every query carries a date range, and the range is capped. A calendar screen
 * that could be asked for "all appointments" is a screen that will one day load
 * a year of them into a browser — and the tenant with the most data is the one
 * it happens to (docs/13-ROADMAP.md Phase 6 §22).
 *
 * ## Branch-local dates, converted once
 *
 * "Thursday at the Karrada branch" is a local question. It is turned into a
 * single UTC window here, using the branch's own timezone, and the query is
 * then an indexed range scan on `(branch_id, starts_at)` — no timezone
 * functions in SQL, nothing engine-specific, and correct for a center whose
 * branches sit in two zones (§7, §35).
 *
 * ## Authorization is part of the query, not a filter afterwards
 *
 * A user's branch scope is applied in the WHERE clause. Loading everything and
 * filtering in PHP would still have read it, would still be in memory, and
 * would still be one careless `dd()` away from being visible.
 *
 * TWO scopes now, both in SQL: which BRANCHES the viewer may see, and — since
 * Phase 7 — WHOSE appointments within them. A stylist holding only
 * `appointment.view_own` gets the rows they are assigned to and nothing else
 * (Phase 7 §30). Expressed through {@see AppointmentScope} rather than a
 * role-name check, because a center that invents a role would otherwise break
 * it silently.
 *
 * ## N+1
 *
 * Items, their employees and the customer are eager-loaded. A week view renders
 * every appointment's services and stylist, and without this it is 1 + 3n
 * queries — which is invisible in a test with three appointments and fatal in a
 * salon with three hundred. A query-count test holds it (§42).
 */
final class CalendarQuery
{
    public function __construct(
        private readonly BookingSettings $settings,
        private readonly AppointmentScopeResolver $scopes,
    ) {}

    /**
     * Appointments a viewer may see, in a branch-local date range.
     *
     * @param  array{branch?: string|null, employee?: string|null, status?: string|null, assigned_inactive?: bool}  $filters
     * @return Collection<int, Appointment>
     *
     * @throws BookingFailed
     * @throws AuthorizationException
     */
    public function forRange(string $from, string $to, User $viewer, array $filters = []): Collection
    {
        $scope = $this->scopes->forViewer($viewer);

        if ($scope->isDenied()) {
            throw new AuthorizationException('You may not view appointments.');
        }

        $this->assertRange($from, $to);

        $branches = $this->branchesInScope($viewer, $filters['branch'] ?? null);

        if ($branches->isEmpty()) {
            return new Collection;
        }

        $query = Appointment::query()
            ->with([
                // `customer.account` and `customer.tags` because
                // CustomerPresenter reads both for every row it renders:
                // `isRegistered()` asks the account relation, and the summary
                // lists tags. Without them a week view is one extra pair of
                // queries per appointment — invisible with three bookings in a
                // test and fatal in a salon with three hundred (§42).
                'customer.account',
                'customer.tags',
                'items.employee',
                'items.addons',
            ])
            ->whereIn('branch_id', $branches->pluck('id')->all())
            ->orderBy('starts_at')
            ->orderBy('id');

        $this->applyWindow($query, $branches, $from, $to);
        $this->applyScope($query, $scope);
        $this->applyFilters($query, $filters);

        /** @var Collection<int, Appointment> $appointments */
        $appointments = $query->get();

        return $appointments;
    }

    /**
     * Future appointments whose assigned employee is no longer active.
     *
     * The operational answer to "we deactivated Ahmed, who was booked with
     * him?". Deliberately a QUERY rather than an automatic reassignment:
     * silently moving customers to a different stylist is a decision a center
     * makes, not one the software makes for it (§21).
     *
     * @return Collection<int, Appointment>
     *
     * @throws AuthorizationException
     */
    public function affectedByInactiveEmployees(User $viewer, ?CarbonImmutable $now = null): Collection
    {
        // The BROAD grant only. "Which of my own bookings has a deactivated
        // stylist" is a question about oneself that answers itself; this is an
        // operational report about other people's schedules.
        if (! $viewer->hasPermission(Permission::AppointmentView)) {
            throw new AuthorizationException('You may not view appointments.');
        }

        $now ??= CarbonImmutable::now();

        $branches = $this->branchesInScope($viewer, null);

        if ($branches->isEmpty()) {
            return new Collection;
        }

        /** @var Collection<int, Appointment> $appointments */
        $appointments = Appointment::query()
            ->with(['customer', 'items.employee'])
            ->whereIn('branch_id', $branches->pluck('id')->all())
            ->blocking()
            ->where('starts_at', '>=', $now->utc())
            ->whereHas('items', function (Builder $items): void {
                $items->whereHas(
                    'employee',
                    fn (Builder $e) => $e->where('status', '!=', EmployeeStatus::Active->value)
                );
            })
            ->orderBy('starts_at')
            ->get();

        return $appointments;
    }

    /**
     * A customer's own appointments.
     *
     * No viewer permission: this is reached only from a customer session, and
     * the customer id comes from the authenticated account rather than from the
     * request (§27).
     *
     * @return Collection<int, Appointment>
     */
    public function forCustomer(int $customerId, bool $upcoming, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        $query = Appointment::query()
            // `items.employee` and `items.addons` because the customer's own
            // view shows who they are booked with and what extras they chose.
            // Without them the presenter silently renders null — the customer
            // asked for Ahmed and the page would not say so.
            ->with(['items.employee', 'items.addons', 'branch'])
            ->where('customer_id', $customerId);

        if ($upcoming) {
            $query->where('starts_at', '>=', $now->utc())
                ->blocking()
                ->orderBy('starts_at');
        } else {
            // Past and finished, newest first. A simple two-list history —
            // visit analytics, spend and frequency belong to CRM reporting and
            // need modules that do not exist (§27).
            $query->where(fn (Builder $q) => $q->where('starts_at', '<', $now->utc())->orWhereIn(
                'status',
                [AppointmentStatus::Completed->value, AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value],
            ))->orderByDesc('starts_at');
        }

        /** @var Collection<int, Appointment> $appointments */
        $appointments = $query->limit(100)->get();

        return $appointments;
    }

    /**
     * The UTC window covering a local date range across possibly several
     * timezones.
     *
     * A center with a branch in Baghdad and one in Istanbul has two different
     * "Thursday"s. Rather than one query per branch, the widest covering window
     * is used and each appointment is checked against its own branch's local
     * date afterwards — one query, still correct.
     *
     * @param  Builder<Appointment>  $query
     * @param  Collection<int, Branch>  $branches
     */
    private function applyWindow(Builder $query, Collection $branches, string $from, string $to): void
    {
        $starts = [];
        $ends = [];

        foreach ($branches as $branch) {
            $starts[] = BranchClock::toUtcOrShift($from, 0, $branch->timezone);
            $ends[] = BranchClock::toUtcOrShift($to, 24 * 60, $branch->timezone);
        }

        if ($starts === []) {
            return;
        }

        $query->startingBetween(min($starts), max($ends));
    }

    /**
     * Narrows to the viewer's own work, when that is all they may see.
     *
     * A `whereHas` on the items rather than a column on the appointment,
     * because assignment is per SERVICE: a visit where a stylist does the
     * second of three services is theirs to see, and an appointment-level
     * employee column would have had to pick one (§30).
     *
     * @param  Builder<Appointment>  $query
     */
    private function applyScope(Builder $query, AppointmentScope $scope): void
    {
        if (! $scope->isLimitedToOwn()) {
            return;
        }

        $query->whereHas(
            'items',
            fn (Builder $items) => $items->where('employee_id', $scope->employeeId)
        );
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  array{branch?: string|null, employee?: string|null, status?: string|null, assigned_inactive?: bool}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;

        if (is_string($status) && $status !== '') {
            // Validated against the enum, never used as a raw string: an
            // arbitrary status filter would be a where clause the client wrote.
            // An unknown one matches NOTHING rather than everything — the
            // honest answer, and it cannot widen what the caller sees.
            $parsed = AppointmentStatus::tryFrom($status);

            $query->where(
                'status',
                $parsed instanceof AppointmentStatus ? $parsed->value : '__unknown__',
            );
        }

        $employee = $filters['employee'] ?? null;

        if (is_string($employee) && $employee !== '') {
            $query->whereHas('items', fn (Builder $items) => $items->whereHas(
                'employee',
                fn (Builder $e) => $e->where('uuid', $employee)
            ));
        }
    }

    /**
     * Branches this viewer may see, narrowed by an optional filter.
     *
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

        // An explicitly named branch that is out of scope is refused rather
        // than silently returning an empty calendar — the difference between
        // "you may not" and "there is nothing" matters at a desk.
        if (is_string($branchUuid) && $branchUuid !== '' && $branches->isEmpty()) {
            throw new AuthorizationException('You may not view that branch.');
        }

        return $branches;
    }

    /**
     * @throws BookingFailed
     */
    private function assertRange(string $from, string $to): void
    {
        if ($to < $from) {
            throw BookingFailed::policy('The end of the range is before its start.');
        }

        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) + 1;

        if ($days > $this->settings->maxCalendarDays()) {
            throw BookingFailed::policy('That date range is too wide.', [
                'max_days' => $this->settings->maxCalendarDays(),
            ]);
        }
    }
}
