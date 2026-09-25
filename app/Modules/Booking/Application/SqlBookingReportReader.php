<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Reporting\BranchWindow;
use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Booking\Contracts\BookingReportReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class SqlBookingReportReader implements BookingReportReader
{
    /** Still ahead of the calendar: a booking that has not reached an outcome. */
    private const OPEN = ['booked', 'confirmed'];

    public function __construct(private ReportConnection $connections) {}

    /**
     * Scheduled work in the period, counted on each branch's own calendar.
     *
     * `total`, `status`, `sources`, `daily`, `hourly`, `branches` and
     * `created` are the original report; the rest is additive:
     *
     *   daily_status / hourly_status  status counts per branch-local bucket
     *   heat         [ISO weekday 1–7][hour 0–23] => bookings (start time)
     *   upcoming     booked or confirmed bookings starting from now on
     *   services     [service id => {name, category, total, status}] per
     *                RESERVED line (one appointment can hold several)
     *   employees    [employee id => {name, total, status}] per reserved line
     */
    public function summary(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);
        $scheduled = $connection->table('appointments')
            ->select(['branch_id', 'status', 'source', 'starts_at']);
        $request->applyWindow($scheduled, 'branch_id', 'starts_at');
        $this->applyFilters($scheduled, $request);

        $status = [];
        $sources = [];
        $daily = [];
        $hourly = [];
        $dailyStatus = [];
        $hourlyStatus = [];
        $heat = [];
        $branches = [];
        $windows = $this->windows($request);
        $now = CarbonImmutable::now('UTC');
        $total = 0;
        $upcoming = 0;

        foreach ($scheduled->orderBy('starts_at')->cursor() as $row) {
            $branchId = (int) $row->branch_id;
            $state = (string) $row->status;
            $source = (string) $row->source;
            $start = CarbonImmutable::parse((string) $row->starts_at, 'UTC');
            $local = $start->setTimezone($windows[$branchId]->timezone);
            $date = $local->toDateString();
            $hour = $local->format('Y-m-d H');

            $total++;
            $status[$state] = ($status[$state] ?? 0) + 1;
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $daily[$date] = ($daily[$date] ?? 0) + 1;
            $hourly[$hour] = ($hourly[$hour] ?? 0) + 1;
            $dailyStatus[$date][$state] = ($dailyStatus[$date][$state] ?? 0) + 1;
            $hourlyStatus[$hour][$state] = ($hourlyStatus[$hour][$state] ?? 0) + 1;
            $heat[$local->dayOfWeekIso][(int) $local->format('G')] = ($heat[$local->dayOfWeekIso][(int) $local->format('G')] ?? 0) + 1;
            $branches[$branchId] = ($branches[$branchId] ?? 0) + 1;

            if (in_array($state, self::OPEN, true) && $start->greaterThanOrEqualTo($now)) {
                $upcoming++;
            }
        }

        ksort($daily);
        ksort($hourly);
        ksort($dailyStatus);
        ksort($hourlyStatus);

        $created = $connection->table('appointments');
        $request->applyWindow($created, 'branch_id', 'created_at');
        $this->applyFilters($created, $request);

        return [
            'total' => $total,
            'created' => $created->count(),
            'status' => $status,
            'sources' => $sources,
            'daily' => $daily,
            'hourly' => $hourly,
            'branches' => $branches,
            'daily_status' => $dailyStatus,
            'hourly_status' => $hourlyStatus,
            'heat' => $heat,
            'upcoming' => $upcoming,
            ...$this->lines($connection, $request),
        ];
    }

    /**
     * The reserved lines of the period's bookings, by service and by booked
     * employee, each split by the booking's status. Grouped in SQL; the
     * names come from a second keyed read (grouping on a JSON column is not
     * portable). A filtered report counts only the matching LINES: "Sara's
     * bookings" are the lines reserved for her, not every line of a booking
     * she appears in.
     *
     * @return array{services: array<int, array{name: string, category: string|null, total: int, status: array<string, int>}>, employees: array<int, array{name: string, total: int, status: array<string, int>}>}
     */
    private function lines(Connection $connection, ReportReadRequest $request): array
    {
        $lines = static function () use ($connection, $request): Builder {
            $query = $connection->table('appointment_items as items')
                ->join('appointments', 'appointments.id', '=', 'items.appointment_id');
            $request->applyWindow($query, 'appointments.branch_id', 'appointments.starts_at');

            return $query;
        };

        $byService = $lines()->selectRaw('items.service_id as target, appointments.status, COUNT(*) as line_count')
            ->whereNotNull('items.service_id')
            ->groupBy('items.service_id', 'appointments.status');
        $this->applyFilters($byService, $request, lines: true);

        $byEmployee = $lines()->selectRaw('items.employee_id as target, appointments.status, COUNT(*) as line_count')
            ->whereNotNull('items.employee_id')
            ->groupBy('items.employee_id', 'appointments.status');
        $this->applyFilters($byEmployee, $request, lines: true);

        $services = $this->grouped($byService->get()->all());
        $employees = $this->grouped($byEmployee->get()->all());

        $serviceRows = $services === [] ? [] : $connection->table('services')
            ->leftJoin('service_categories as categories', 'categories.id', '=', 'services.service_category_id')
            ->whereIn('services.id', array_keys($services))
            ->get(['services.id', 'services.name', 'categories.name as category_name'])
            ->keyBy('id')
            ->all();
        $employeeNames = $employees === [] ? [] : $connection->table('employees')
            ->whereIn('id', array_keys($employees))
            ->pluck('name', 'id')
            ->all();

        foreach ($services as $id => $counts) {
            $row = $serviceRows[$id] ?? null;
            $services[$id] = [
                'name' => self::translated($row?->name),
                'category' => $row !== null && $row->category_name !== null ? self::translated($row->category_name) : null,
            ] + $counts;
        }

        foreach ($employees as $id => $counts) {
            $employees[$id] = ['name' => self::translated($employeeNames[$id] ?? null)] + $counts;
        }

        return ['services' => $services, 'employees' => $employees];
    }

    /**
     * @param  list<object>  $rows  {target, status, line_count}
     * @return array<int, array{total: int, status: array<string, int>}>
     */
    private function grouped(array $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $id = (int) $row->target;
            $count = (int) $row->line_count;
            $grouped[$id] ??= ['total' => 0, 'status' => []];
            $grouped[$id]['total'] += $count;
            $grouped[$id]['status'][(string) $row->status] = ($grouped[$id]['status'][(string) $row->status] ?? 0) + $count;
        }

        return $grouped;
    }

    /**
     * Optional narrowing: `source` (a booking channel code) and `status` (an
     * appointment status) on the booking; `employee`, `service` (uuids) and
     * `category` (a service category uuid) on the RESERVED lines. A uuid is
     * resolved inside this query, so an unknown one matches nothing — it can
     * never widen the branch windows applied above.
     *
     * With `$lines` the query already reads `appointment_items as items`,
     * and the line filters apply to that line itself.
     */
    private function applyFilters(Builder $query, ReportReadRequest $request, bool $lines = false): void
    {
        foreach (['source' => 'appointments.source', 'status' => 'appointments.status'] as $key => $column) {
            $value = $request->filter($key);

            if (is_string($value) && $value !== '') {
                $query->where($column, $value);
            }
        }

        $employee = $request->filter('employee');
        $service = $request->filter('service');
        $category = $request->filter('category');
        $filters = [
            'employee' => is_string($employee) && $employee !== '' ? $employee : null,
            'service' => is_string($service) && $service !== '' ? $service : null,
            'category' => is_string($category) && $category !== '' ? $category : null,
        ];

        if (array_filter($filters) === []) {
            return;
        }

        if ($lines) {
            $this->lineFilters($query, 'items', $filters);

            return;
        }

        $query->whereExists(function (Builder $items) use ($filters): void {
            $items->selectRaw('1')
                ->from('appointment_items as filter_items')
                ->whereColumn('filter_items.appointment_id', 'appointments.id');
            $this->lineFilters($items, 'filter_items', $filters);
        });
    }

    /**
     * @param  array{employee: string|null, service: string|null, category: string|null}  $filters
     */
    private function lineFilters(Builder $query, string $items, array $filters): void
    {
        if ($filters['employee'] !== null) {
            $query->whereIn($items.'.employee_id', static fn (Builder $employees) => $employees->select('id')
                ->from('employees')
                ->where('uuid', $filters['employee']));
        }

        if ($filters['service'] !== null) {
            $query->whereIn($items.'.service_id', static fn (Builder $services) => $services->select('id')
                ->from('services')
                ->where('uuid', $filters['service']));
        }

        if ($filters['category'] !== null) {
            $query->whereIn($items.'.service_id', static fn (Builder $services) => $services->select('services.id')
                ->from('services')
                ->join('service_categories as filter_categories', 'filter_categories.id', '=', 'services.service_category_id')
                ->where('filter_categories.uuid', $filters['category']));
        }
    }

    /** @return array<int, BranchWindow> */
    private function windows(ReportReadRequest $request): array
    {
        $indexed = [];

        foreach ($request->windows as $window) {
            $indexed[$window->branchId] = $window;
        }

        return $indexed;
    }

    private static function translated(mixed $json): string
    {
        $values = json_decode((string) $json, true);
        $values = is_array($values) ? $values : [];

        return (string) ($values[app()->getLocale()] ?? $values['en'] ?? reset($values) ?: '—');
    }
}
