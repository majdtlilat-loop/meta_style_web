<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Data\ResolvedLine;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use Illuminate\Support\Facades\DB;

/**
 * Who can perform a service at a branch, and which of them is free.
 *
 * ## The "any available" policy
 *
 * The lowest employee id among those who are eligible, at this branch, active,
 * and not already booked across the whole window.
 *
 * Simple on purpose. "Earliest valid availability" — the stated preference — is
 * identical for every free employee at a fixed candidate start, so the tie-break
 * is what actually decides, and it has to be DETERMINISTIC: the same request
 * must produce the same assignment twice in a row, or an idempotent retry would
 * quietly book a different person than the confirmation the customer already
 * saw (docs/13-ROADMAP.md Phase 6 §5).
 *
 * Explicitly NOT here: load balancing, rotation, revenue fairness, skill
 * ranking, customer preference history, AI recommendation. Every one of them is
 * a policy a center would want to configure, and none has been asked for. The
 * seam is this class — a center-configurable strategy replaces the ordering
 * below and nothing else moves.
 *
 * ## Greedy assignment across a multi-service booking is safe
 *
 * Items in one appointment run sequentially and never overlap, so choosing per
 * item independently cannot make the appointment conflict with itself, and one
 * employee may take several of its services. There is no arrangement a smarter
 * assignment would find that this misses.
 */
final class EmployeeAssigner
{
    /** @var array<string, list<int>> */
    private array $eligible = [];

    public function __construct(
        private readonly ConflictFinder $conflicts,
        private readonly BlockFinder $blocks,
    ) {}

    /**
     * Employees who MAY perform this service at this branch, lowest id first.
     *
     * One query, joining the two Phase 4 pivots. Ordered by id so the "any
     * available" choice is stable.
     *
     * @return list<int>
     */
    public function eligibleIds(Service $service, Branch $branch): array
    {
        $key = $service->getKey().':'.$branch->getKey();

        if (isset($this->eligible[$key])) {
            return $this->eligible[$key];
        }

        /** @var list<int> $ids */
        $ids = DB::connection('tenant')
            ->table('employee_service')
            ->join('employees', 'employees.id', '=', 'employee_service.employee_id')
            ->join('employee_branches', 'employee_branches.employee_id', '=', 'employees.id')
            ->where('employee_service.service_id', $service->getKey())
            ->where('employee_branches.branch_id', $branch->getKey())
            ->where('employees.status', EmployeeStatus::Active->value)
            ->orderBy('employees.id')
            ->distinct()
            ->pluck('employees.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $this->eligible[$key] = $ids;
    }

    /**
     * Every employee who could be involved in these lines, for a bulk busy
     * lookup.
     *
     * @param  list<ResolvedLine>  $lines
     * @return list<int>
     */
    public function candidateIds(array $lines, Branch $branch): array
    {
        $ids = [];

        foreach ($lines as $line) {
            if ($line->employeeId !== null) {
                $ids[] = $line->employeeId;

                continue;
            }

            foreach ($this->eligibleIds($line->service, $branch) as $id) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Decides who takes each line, or reports that nobody can.
     *
     * IN MEMORY, against a busy map loaded once for the whole date range. The
     * database form of this question is one query per employee per candidate
     * start, which for a week of 15-minute slots across a dozen stylists is
     * tens of thousands of round trips for one screen (§34).
     *
     * @param  list<ResolvedLine>  $lines
     * @param  list<TimeWindow>  $windows  one per line, same order
     * @param  array<int, list<TimeWindow>>  $busy
     * @return array<int, int>|null line position => employee id, or null if
     *                              any line cannot be staffed
     */
    public function assign(array $lines, array $windows, array $busy, Branch $branch): ?array
    {
        $assignments = [];

        foreach ($lines as $position => $line) {
            $window = $windows[$position];

            $candidates = $line->employeeId !== null
                ? [$line->employeeId]
                : $this->eligibleIds($line->service, $branch);

            $chosen = null;

            foreach ($candidates as $candidate) {
                if (! $this->isBusy($candidate, $window, $busy)) {
                    $chosen = $candidate;

                    break;
                }
            }

            if ($chosen === null) {
                // A single unstaffable line rejects the WHOLE booking. A visit
                // is one arrival: booking two of the three services the
                // customer asked for and silently dropping the third is worse
                // than saying no (§4).
                return null;
            }

            $assignments[$position] = $chosen;

            // Provisionally busy for the rest of THIS booking. Items never
            // overlap so this changes nothing today — but it is the line that
            // keeps the assigner correct if parallel services ever arrive.
            $busy[$chosen][] = $window;
        }

        return $assignments;
    }

    /**
     * The authoritative single-employee check, straight to the database.
     *
     * Used inside the booking transaction while the lock is held. The in-memory
     * map above is a snapshot; this is the answer that counts (§9).
     *
     * TWO SOURCES, both authoritative: an appointment already booked, and a
     * Phase 7 availability block. Checking only the first is how a booking
     * lands in the middle of somebody's training day when the block was written
     * a moment after availability was computed (Phase 7 §13).
     */
    public function isEmployeeFree(
        int $employeeId,
        TimeWindow $window,
        int $branchId,
        ?int $ignoreAppointmentId = null,
    ): bool {
        if ($this->conflicts->isEmployeeBusy($employeeId, $window, $ignoreAppointmentId)) {
            return false;
        }

        return ! $this->blocks->isBlocked($employeeId, $branchId, $window);
    }

    /**
     * @param  array<int, list<TimeWindow>>  $busy
     */
    private function isBusy(int $employeeId, TimeWindow $window, array $busy): bool
    {
        foreach ($busy[$employeeId] ?? [] as $taken) {
            if ($window->overlaps($taken)) {
                return true;
            }
        }

        return false;
    }
}
