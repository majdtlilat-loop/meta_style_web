<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Authorization\AppointmentScope;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Branches\Domain\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Manager dashboard's appointment reads: today's book, what comes after
 * today, and who is booked today.
 *
 * The same two scopes as the calendar, both in SQL: which BRANCHES the viewer
 * may see, and — through {@see AppointmentScopeResolver} — whether they see
 * the whole book or only the rows they are assigned to (`view_own`). "Today"
 * is each branch's OWN local day, converted once into a UTC window, so a
 * center with branches in two timezones gets two correct todays.
 *
 * Read-only, bounded, eager-loaded, and it returns plain arrays: the
 * dashboard never touches the Appointment model (BookingBoundaryTest).
 */
final class DashboardAppointments
{
    public function __construct(private readonly AppointmentScopeResolver $scopes) {}

    /**
     * Today's bookings: counts by status, and the ones still ahead (or in
     * progress) in time order.
     *
     * @return array{total: int, remaining: int, status: array<string, int>, items: list<array<string, mixed>>}
     */
    public function today(User $viewer, ?string $branchUuid = null, int $limit = 8, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $empty = ['total' => 0, 'remaining' => 0, 'status' => [], 'items' => []];
        $scope = $this->scopes->forViewer($viewer);
        $branches = $scope->isDenied() ? new Collection : $this->branches($viewer, $branchUuid);

        if ($branches->isEmpty()) {
            return $empty;
        }

        $base = fn (): Builder => $this->scoped($this->todayWindow(Appointment::query(), $branches, $now), $scope);

        $status = $base()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        /** @var Collection<int, Appointment> $ahead */
        $ahead = $base()
            ->with(['customer', 'branch', 'items.employee'])
            ->blocking()
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(max(1, min(50, $limit)))
            ->get();

        return [
            'total' => array_sum($status),
            'remaining' => $base()->blocking()->where('ends_at', '>', $now)->count(),
            'status' => $status,
            'items' => $ahead->map(fn (Appointment $appointment): array => $this->present($appointment, $now))->values()->all(),
        ];
    }

    /**
     * Bookings after today — the next ones that still hold a slot.
     *
     * @return list<array<string, mixed>>
     */
    public function upcoming(User $viewer, ?string $branchUuid = null, int $limit = 6, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $scope = $this->scopes->forViewer($viewer);
        $branches = $scope->isDenied() ? new Collection : $this->branches($viewer, $branchUuid);

        if ($branches->isEmpty()) {
            return [];
        }

        $query = Appointment::query()
            ->with(['customer', 'branch', 'items.employee'])
            ->blocking()
            ->where(function (Builder $outer) use ($branches, $now): void {
                foreach ($branches as $branch) {
                    $timezone = $this->timezone($branch);
                    $tomorrow = BranchClock::localDayStartAfter($now, 1, $timezone);

                    $outer->orWhere(fn (Builder $q) => $q->where('branch_id', $branch->getKey())->where('starts_at', '>=', $tomorrow));
                }
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(max(1, min(50, $limit)));

        /** @var Collection<int, Appointment> $appointments */
        $appointments = $this->scoped($query, $scope)->get();

        return $appointments->map(fn (Appointment $appointment): array => $this->present($appointment, $now))->values()->all();
    }

    /**
     * Employees with at least one booking today that was not cancelled or a
     * no-show — who is BOOKED, never who is present. There is no attendance
     * record, and this does not pretend to be one.
     *
     * The broad `appointment.view` only: other people's schedules are not a
     * `view_own` question.
     *
     * @return list<array{uuid: string, name: string, bookings: int}>
     */
    public function bookedEmployeesToday(User $viewer, ?string $branchUuid = null, ?CarbonImmutable $now = null): array
    {
        if (! $viewer->hasPermission(Permission::AppointmentView)) {
            return [];
        }

        $now = ($now ?? CarbonImmutable::now())->utc();
        $branches = $this->branches($viewer, $branchUuid);

        if ($branches->isEmpty()) {
            return [];
        }

        $items = (new AppointmentItem)->getTable();
        $appointments = (new Appointment)->getTable();

        $query = DB::connection('tenant')->table($items.' as items')
            ->join($appointments.' as appointments', 'appointments.id', '=', 'items.appointment_id')
            ->join('employees', 'employees.id', '=', 'items.employee_id')
            ->whereNotIn('appointments.status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
            ->where(function ($outer) use ($branches, $now): void {
                foreach ($branches as $branch) {
                    [$from, $until] = $this->todayBounds($branch, $now);

                    $outer->orWhere(fn ($q) => $q->where('appointments.branch_id', $branch->getKey())
                        ->where('appointments.starts_at', '>=', $from)
                        ->where('appointments.starts_at', '<', $until));
                }
            })
            ->selectRaw('employees.uuid, employees.name, COUNT(DISTINCT appointments.id) AS bookings')
            ->groupBy('employees.id', 'employees.uuid', 'employees.name')
            ->orderByDesc('bookings')
            ->orderBy('employees.id');

        return $query->get()->map(static function (object $row): array {
            $names = json_decode((string) $row->name, true);
            $names = is_array($names) ? $names : [];

            return [
                'uuid' => (string) $row->uuid,
                'name' => (string) ($names[app()->getLocale()] ?? $names['en'] ?? (reset($names) ?: '—')),
                'bookings' => (int) $row->bookings,
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Appointment $appointment, CarbonImmutable $now): array
    {
        $services = [];
        $employees = [];

        foreach ($appointment->items as $item) {
            $services[] = $item->service_name->get();

            if ($item->relationLoaded('employee') && $item->employee !== null) {
                $employees[$item->employee->uuid] = $item->employee->name->get();
            }
        }

        $local = $appointment->localStart();

        return [
            'uuid' => $appointment->uuid,
            'reference' => $appointment->reference,
            'status' => $appointment->status->value,
            'local_date' => $appointment->localDate(),
            'local_start' => $local->format('H:i'),
            'local_end' => $appointment->localEnd()->format('H:i'),
            'starts_at' => $appointment->starts_at->toIso8601String(),
            'in_progress' => $appointment->starts_at->lessThanOrEqualTo($now) && $appointment->ends_at->greaterThan($now),
            'branch' => $appointment->branch?->name->get() ?? '',
            // The name only: contact details stay with CustomerPresenter.
            'customer' => $appointment->customer?->name,
            'services' => array_values(array_filter($services, static fn (?string $name): bool => $name !== null && $name !== '')),
            'employees' => array_values($employees),
        ];
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    private function scoped(Builder $query, AppointmentScope $scope): Builder
    {
        if ($scope->isLimitedToOwn()) {
            $query->whereHas('items', fn (Builder $items) => $items->where('employee_id', $scope->employeeId));
        }

        return $query;
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  Collection<int, Branch>  $branches
     * @return Builder<Appointment>
     */
    private function todayWindow(Builder $query, Collection $branches, CarbonImmutable $now): Builder
    {
        return $query->where(function (Builder $outer) use ($branches, $now): void {
            foreach ($branches as $branch) {
                [$from, $until] = $this->todayBounds($branch, $now);

                $outer->orWhere(fn (Builder $q) => $q->where('branch_id', $branch->getKey())
                    ->where('starts_at', '>=', $from)
                    ->where('starts_at', '<', $until));
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function todayBounds(Branch $branch, CarbonImmutable $now): array
    {
        $timezone = $this->timezone($branch);
        $today = BranchClock::localDate($now, $timezone);

        return [BranchClock::toUtcOrShift($today, 0, $timezone), BranchClock::localDayStartAfter($now, 1, $timezone)];
    }

    private function timezone(Branch $branch): string
    {
        return $branch->timezone !== '' ? $branch->timezone : 'UTC';
    }

    /**
     * Active branches in the viewer's scope, optionally narrowed to one. A
     * branch outside the scope narrows to NOTHING — never back to everything.
     *
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
