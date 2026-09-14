<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Application\AppointmentScopeResolver;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The staff queue board: who is waiting, who has been called, who is being
 * served.
 *
 * ## Bounded to one branch-local day, always
 *
 * `(branch_id, business_date, …)` is an indexed range, and the alternative —
 * "every open ticket" — is a board that grows until somebody notices. A ticket
 * left open from last Tuesday is an operational problem, not something the
 * board should quietly carry forever (docs/17-QUEUE.md §19).
 *
 * ## Own-scope reuses the Phase 7 rule
 *
 * `journey.view` sees the branch; `journey.view_own` sees the stages assigned
 * to the viewer's own employee record; neither is refused. Resolved through
 * `AppointmentScopeResolver`, so there is one answer to "what does this person
 * see" rather than a queue-shaped copy of it that would drift (Phase 7 §30).
 *
 * Employees are scoped on the ACTUAL assignment — `journey_stages.employee_id`
 * — because that is who is really doing the work, and a stylist handed a
 * customer mid-shift should see them.
 *
 * ## Two queries, never a query per row
 *
 * Everything a row renders is eager-loaded: the journey, its customer, the
 * stage, the destination. A board with two hundred tickets and a lazy relation
 * is two hundred queries, which is the mistake §19 exists to prevent.
 */
final class QueueBoardQuery
{
    public function __construct(private readonly AppointmentScopeResolver $scopes) {}

    /**
     * One branch-local day of tickets, ready to group.
     *
     * @param  array{branch?: string|null, department?: string|null, service_point?: string|null, employee?: string|null, state?: string|null}  $filters
     * @return list<QueueTicket>
     *
     * @throws AuthorizationException
     */
    public function forDay(User $viewer, ?string $date = null, array $filters = []): array
    {
        if (! $viewer->hasPermission(Permission::QueueView)) {
            throw new AuthorizationException('You may not view the queue.');
        }

        $scope = $this->scopes->forJourneyViewer($viewer);

        if ($scope->isDenied()) {
            throw new AuthorizationException('You may not view the queue.');
        }

        $branches = $this->branchesInScope($viewer, $filters['branch'] ?? null);

        if ($branches->isEmpty()) {
            return [];
        }

        $query = QueueTicket::query()
            ->with([
                'journey.customer',
                'journey.appointment.customer',
                'stage.employee',
                'department',
                'servicePoint',
            ])
            ->whereIn('branch_id', $branches->pluck('id')->all())
            ->whereIn('business_date', $this->businessDates($branches, $date))
            ->inCallOrder();

        if ($scope->isLimitedToOwn()) {
            /*
             * A stage this employee is actually performing. `whereHas` rather
             * than a fetched id list: the set is small and the database is
             * better at it than PHP, and a list would have to be bounded
             * somewhere arbitrary.
             */
            $query->whereHas('stage', fn (Builder $q) => $q->where('employee_id', $scope->employeeId));
        }

        $this->applyFilters($query, $filters);

        /** @var list<QueueTicket> $tickets */
        $tickets = $query->get()->all();

        return $tickets;
    }

    /**
     * The next ticket a desk should call, if there is one.
     *
     * The SAME ordering the board renders — `priority DESC, issued_at ASC,
     * id ASC` — so "next" on the screen and "next" from this method can never
     * be two different customers (§20).
     *
     * @param  array{department?: string|null, service_point?: string|null}  $filters
     */
    public function nextToCall(Branch $branch, ?string $date = null, array $filters = []): ?QueueTicket
    {
        $query = QueueTicket::query()
            ->with(['journey.customer', 'journey.appointment.customer', 'department', 'servicePoint'])
            ->where('branch_id', $branch->getKey())
            ->where('business_date', BranchClock::localDate(
                $date === null ? CarbonImmutable::now()->utc() : CarbonImmutable::parse($date.' 12:00', $branch->timezone)->utc(),
                $branch->timezone,
            ))
            // Only `waiting`. A called ticket is already somebody's
            // responsibility and a held one is deliberately out of rotation.
            ->whereIn('state', TicketState::callableValues())
            ->inCallOrder();

        $this->applyFilters($query, $filters);

        return $query->first();
    }

    /**
     * @param  Builder<QueueTicket>  $query
     * @param  array{branch?: string|null, department?: string|null, service_point?: string|null, employee?: string|null, state?: string|null}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $department = $filters['department'] ?? null;

        if (is_string($department) && $department !== '') {
            $id = DB::connection('tenant')->table('departments')->where('uuid', $department)->value('id');

            // An unknown department matches NOTHING rather than everything: the
            // honest answer, and it cannot widen what the caller sees.
            $query->where('department_id', $id === null ? -1 : (int) $id);
        }

        $point = $filters['service_point'] ?? null;

        if (is_string($point) && $point !== '') {
            $id = DB::connection('tenant')->table('queue_service_points')->where('uuid', $point)->value('id');

            $query->where('service_point_id', $id === null ? -1 : (int) $id);
        }

        $employee = $filters['employee'] ?? null;

        if (is_string($employee) && $employee !== '') {
            $id = DB::connection('tenant')->table('employees')->where('uuid', $employee)->value('id');

            $employeeId = $id === null ? -1 : (int) $id;

            $query->whereHas('stage', fn (Builder $q) => $q->where('employee_id', $employeeId));
        }

        $state = $filters['state'] ?? null;

        if (is_string($state) && $state !== '') {
            $query->where('state', $state);
        }
    }

    /**
     * The local dates covering "today" across possibly several timezones.
     *
     * A center with a branch in Baghdad and one in Istanbul has two different
     * todays. Both are included rather than picking one, which is the same
     * approach the Phase 6 calendar and the Phase 7 board take.
     *
     * @param  Collection<int, Branch>  $branches
     * @return list<string>
     */
    private function businessDates(Collection $branches, ?string $date): array
    {
        if ($date !== null) {
            return [$date];
        }

        $now = CarbonImmutable::now()->utc();
        $dates = [];

        foreach ($branches as $branch) {
            $dates[] = BranchClock::localDate($now, $branch->timezone);
        }

        return array_values(array_unique($dates));
    }

    /**
     * @return Collection<int, Branch>
     *
     * @throws AuthorizationException
     */
    private function branchesInScope(User $viewer, ?string $branchUuid): Collection
    {
        /** @var Collection<int, Branch> $branches */
        $branches = Branch::query()->orderBy('id')->get()
            ->filter(fn (Branch $branch): bool => $viewer->canAccessBranch((int) $branch->getKey()))
            ->values();

        if ($branchUuid === null || $branchUuid === '') {
            return $branches;
        }

        $chosen = $branches->firstWhere('uuid', $branchUuid);

        if (! $chosen instanceof Branch) {
            // A branch outside the viewer's scope is refused rather than
            // silently widened to everything they can see.
            throw new AuthorizationException('You may not work in that branch.');
        }

        return collect([$chosen]);
    }
}
