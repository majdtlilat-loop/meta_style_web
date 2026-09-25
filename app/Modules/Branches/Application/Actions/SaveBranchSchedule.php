<?php

declare(strict_types=1);

namespace App\Modules\Branches\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces a branch's weekly hours and its date exceptions.
 *
 * REPLACE, not merge. A schedule is edited as a whole in the UI — the owner
 * looks at the week, changes it, and saves. Diffing individual intervals would
 * mean deciding what a "changed" interval is, and getting that wrong silently
 * leaves an old row behind that reopens the shop on a Tuesday.
 *
 * Overnight intervals are legitimate and are not "invalid": a barber open
 * 20:00–02:00 stores exactly that, and `closes_at <= opens_at` is what says it
 * crosses midnight. What IS rejected is an interval of zero length, which can
 * only be a mistake.
 */
final class SaveBranchSchedule
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<array{day_of_week: int, opens_at: string, closes_at: string}>  $hours
     * @param  list<array{date: string, is_closed: bool, opens_at?: string|null, closes_at?: string|null, note?: string|null}>  $exceptions
     */
    public function __invoke(Branch $branch, array $hours, array $exceptions, User $actingUser): Branch
    {
        $this->authorize($branch, $actingUser);

        $hours = $this->validateHours($hours);
        $exceptions = $this->validateExceptions($exceptions);

        $before = [
            'intervals' => $branch->workingHours()->count(),
            'exceptions' => $branch->hourExceptions()->count(),
        ];

        DB::connection('tenant')->transaction(function () use ($branch, $hours, $exceptions): void {
            $branch->workingHours()->delete();
            $branch->hourExceptions()->delete();

            foreach ($hours as $index => $interval) {
                $branch->workingHours()->create([
                    'day_of_week' => $interval['day_of_week'],
                    'opens_at' => $interval['opens_at'],
                    'closes_at' => $interval['closes_at'],
                    'sort_order' => $index,
                ]);
            }

            foreach ($exceptions as $exception) {
                $branch->hourExceptions()->create([
                    'date' => $exception['date'],
                    'is_closed' => $exception['is_closed'],
                    'opens_at' => $exception['is_closed'] ? null : ($exception['opens_at'] ?? null),
                    'closes_at' => $exception['is_closed'] ? null : ($exception['closes_at'] ?? null),
                    'note' => $exception['note'] ?? null,
                ]);
            }
        });

        $this->audit->record(new AuditEvent(
            action: 'branches.working_hours.changed',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Branch::class,
            targetId: $branch->uuid,
            targetLabel: (string) $branch->name,
            before: $before,
            after: ['intervals' => count($hours), 'exceptions' => count($exceptions)],
        ));

        return $branch->refresh();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Branch $branch, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::BranchManage)) {
            throw new AuthorizationException(__('manager_staff.errors.branch_manage_denied'));
        }

        if (! $actingUser->branchScope()->allows($branch->id)) {
            throw new AuthorizationException(__('manager_staff.errors.branch_denied'));
        }
    }

    /**
     * @param  list<array{day_of_week: int, opens_at: string, closes_at: string}>  $hours
     * @return list<array{day_of_week: int, opens_at: string, closes_at: string}>
     *
     * @throws ValidationException
     */
    private function validateHours(array $hours): array
    {
        $clean = [];

        foreach ($hours as $interval) {
            $day = $interval['day_of_week'];

            if ($day < 0 || $day > 6) {
                throw ValidationException::withMessages([
                    'hours' => __('manager_staff.errors.hours_day'),
                ]);
            }

            $opens = $this->normaliseTime($interval['opens_at']);
            $closes = $this->normaliseTime($interval['closes_at']);

            // Equal times are the one case that cannot be meant: neither a
            // normal interval nor an overnight one, just a zero-length window
            // that would make the branch open for no time at all.
            if ($opens === $closes) {
                throw ValidationException::withMessages([
                    'hours' => __('manager_staff.errors.hours_zero', ['time' => $opens]),
                ]);
            }

            $clean[] = ['day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes];
        }

        $this->assertNoOverlaps($clean);

        return $clean;
    }

    /**
     * Rejects a day whose intervals overlap.
     *
     * Two overlapping windows on one day are not a split shift, they are a
     * mistake — and Booking would later have to pick one arbitrarily. Compared
     * in minutes-from-midnight, with overnight intervals extended past 1440 so
     * 20:00–02:00 and 01:00–03:00 are correctly seen to collide.
     *
     * @param  list<array{day_of_week: int, opens_at: string, closes_at: string}>  $hours
     *
     * @throws ValidationException
     */
    private function assertNoOverlaps(array $hours): void
    {
        $byDay = [];

        foreach ($hours as $interval) {
            $start = $this->minutes($interval['opens_at']);
            $end = $this->minutes($interval['closes_at']);

            if ($end <= $start) {
                $end += 1440;
            }

            $byDay[$interval['day_of_week']][] = [$start, $end];
        }

        foreach ($byDay as $day => $intervals) {
            sort($intervals);

            for ($i = 1, $count = count($intervals); $i < $count; $i++) {
                if ($intervals[$i][0] < $intervals[$i - 1][1]) {
                    throw ValidationException::withMessages([
                        'hours' => __('manager_staff.errors.hours_overlap', ['day' => __('manager_staff.days.'.$day)]),
                    ]);
                }
            }
        }
    }

    /**
     * @param  list<array{date: string, is_closed: bool, opens_at?: string|null, closes_at?: string|null, note?: string|null}>  $exceptions
     * @return list<array{date: string, is_closed: bool, opens_at?: string|null, closes_at?: string|null, note?: string|null}>
     *
     * @throws ValidationException
     */
    private function validateExceptions(array $exceptions): array
    {
        $clean = [];
        $seen = [];

        foreach ($exceptions as $exception) {
            // One answer per date. Two rows for Eid — one closed, one open
            // late — would leave Booking to pick one arbitrarily.
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $exception['date']);

            if ($date === false || $date->format('Y-m-d') !== $exception['date']) {
                throw ValidationException::withMessages([
                    'exceptions' => __('manager_staff.errors.exception_date'),
                ]);
            }

            if (isset($seen[$exception['date']])) {
                throw ValidationException::withMessages([
                    'exceptions' => __('manager_staff.errors.exception_duplicate', ['date' => $exception['date']]),
                ]);
            }

            $seen[$exception['date']] = true;

            if (! $exception['is_closed']) {
                $opens = $exception['opens_at'] ?? null;
                $closes = $exception['closes_at'] ?? null;

                if (! is_string($opens) || ! is_string($closes)) {
                    throw ValidationException::withMessages([
                        'exceptions' => __('manager_staff.errors.exception_times'),
                    ]);
                }

                $exception['opens_at'] = $this->normaliseTime($opens);
                $exception['closes_at'] = $this->normaliseTime($closes);
            }

            $clean[] = $exception;
        }

        return $clean;
    }

    /** `9:00`, `09:00` and `09:00:00` all mean the same thing. */
    private function normaliseTime(string $time): string
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return ($hours * 60) + $minutes;
    }
}
