<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Application\AppointmentScopeResolver;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * "Who is in the building", for the Manager dashboard: visits still in
 * progress that arrived during each branch's CURRENT local day, and how many
 * of their stages are being performed this minute.
 *
 * A visit left open from yesterday is not on the floor now, so the day bound
 * is the point. Branch scope applies through the journey's own branch (walk
 * in) or its appointment's (booked); `journey.view_own` narrows to visits with
 * a stage the viewer's employee record actually performs.
 */
final class DashboardFloor
{
    public function __construct(private readonly AppointmentScopeResolver $scopes) {}

    /**
     * @return array{active: int, in_service: int, waiting: int}|null null when the viewer may not see visits
     */
    public function now(User $viewer, ?string $branchUuid = null, ?CarbonImmutable $now = null): ?array
    {
        $scope = $this->scopes->forJourneyViewer($viewer);

        if ($scope->isDenied()) {
            return null;
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $branches = $this->branches($viewer, $branchUuid);

        if ($branches->isEmpty()) {
            return ['active' => 0, 'in_service' => 0, 'waiting' => 0];
        }

        $journeys = ServiceJourney::query()
            ->where('status', JourneyStatus::Active->value)
            ->where(function (Builder $outer) use ($branches, $now): void {
                foreach ($branches as $branch) {
                    $timezone = $branch->timezone !== '' ? $branch->timezone : 'UTC';
                    $from = BranchClock::toUtcOrShift(BranchClock::localDate($now, $timezone), 0, $timezone);
                    $until = BranchClock::localDayStartAfter($now, 1, $timezone);
                    $id = (int) $branch->getKey();

                    $outer->orWhere(fn (Builder $day) => $day
                        ->where('arrived_at', '>=', $from)
                        ->where('arrived_at', '<', $until)
                        ->where(fn (Builder $at) => $at->where('branch_id', $id)
                            ->orWhereHas('appointment', fn (Builder $appointment) => $appointment->where('branch_id', $id))));
                }
            });

        if ($scope->isLimitedToOwn()) {
            $journeys->whereHas('stages', fn (Builder $stages) => $stages->where('employee_id', $scope->employeeId));
        }

        $stages = JourneyStage::query()->whereIn('service_journey_id', (clone $journeys)->select('id'));

        if ($scope->isLimitedToOwn()) {
            $stages->where('employee_id', $scope->employeeId);
        }

        $byStatus = $stages
            ->whereIn('status', [StageStatus::InService->value, StageStatus::Waiting->value])
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'active' => (clone $journeys)->count(),
            'in_service' => $byStatus[StageStatus::InService->value] ?? 0,
            'waiting' => $byStatus[StageStatus::Waiting->value] ?? 0,
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
