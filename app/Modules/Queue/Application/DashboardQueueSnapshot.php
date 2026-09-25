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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Who is waiting right now", for the Manager dashboard.
 *
 * Bounded to each branch's CURRENT business date, exactly like the queue
 * board: a ticket left open from last Tuesday is an operational problem, not
 * somebody waiting now, and counting it would put a phantom queue on the
 * overview (docs/17-QUEUE.md §19). Branch scope and the viewer's own-work
 * scope (`journey.view_own`, through the stage's actual employee) are applied
 * in SQL, the same resolver the board uses.
 */
final class DashboardQueueSnapshot
{
    public function __construct(private readonly AppointmentScopeResolver $scopes) {}

    /**
     * @return array{states: array<string, int>, open: int, longest_wait_minutes: int|null}|null
     *                                                                                           null when the viewer may not see the queue at all
     */
    public function now(User $viewer, ?string $branchUuid = null, ?CarbonImmutable $now = null): ?array
    {
        if (! $viewer->hasPermission(Permission::QueueView)) {
            return null;
        }

        $scope = $this->scopes->forJourneyViewer($viewer);

        if ($scope->isDenied()) {
            return null;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $branches = $this->branches($viewer, $branchUuid);
        $empty = ['states' => [], 'open' => 0, 'longest_wait_minutes' => null];

        if ($branches->isEmpty()) {
            return $empty;
        }

        $query = QueueTicket::query()
            ->open()
            ->where(function (Builder $outer) use ($branches, $now): void {
                foreach ($branches as $branch) {
                    $timezone = $branch->timezone !== '' ? $branch->timezone : 'UTC';

                    $outer->orWhere(fn (Builder $q) => $q->where('branch_id', $branch->getKey())
                        ->where('business_date', BranchClock::localDate($now, $timezone)));
                }
            });

        if ($scope->isLimitedToOwn()) {
            $query->whereHas('stage', fn (Builder $q) => $q->where('employee_id', $scope->employeeId));
        }

        $states = (clone $query)
            ->selectRaw('state, COUNT(*) AS aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $oldest = (clone $query)
            ->where('state', TicketState::Waiting->value)
            ->min('issued_at');

        $longest = null;

        if ($oldest !== null) {
            $longest = max(0, (int) CarbonImmutable::parse((string) $oldest, 'UTC')->diffInMinutes($now));
        }

        return [
            'states' => $states,
            'open' => array_sum($states),
            'longest_wait_minutes' => $longest,
        ];
    }

    /**
     * @return Collection<int, Branch>
     */
    private function branches(User $viewer, ?string $branchUuid): Collection
    {
        $query = Branch::query()->active();
        $viewer->branchScope()->applyTo($query, 'id');

        if (is_string($branchUuid) && $branchUuid !== '') {
            $query->where('uuid', $branchUuid);
        }

        /** @var Collection<int, Branch> $branches */
        $branches = $query->orderBy('id')->get();

        return $branches;
    }
}
