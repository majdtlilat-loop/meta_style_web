<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application;

use App\Kernel\Reporting\BranchWindow;
use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\ServiceJourney\Contracts\JourneyReportReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class SqlJourneyReportReader implements JourneyReportReader
{
    public function __construct(private ReportConnection $connections) {}

    /**
     * Visits that ARRIVED in the period (branch-local), and the work their
     * stages actually delivered. Only completed stages are attributed, to the
     * employee recorded on the stage — never merely the one booked.
     *
     * Additive to the original shape: `stages.daily` / `stages.hourly`
     * (completed services per branch-local bucket of the visit's arrival).
     * Menu categories are the Catalog's to name (ADR-037): a report groups
     * `stages.services` (keyed by service id) through the Catalog contract.
     */
    public function summary(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);
        $base = $connection->table('service_journeys as journeys')
            ->leftJoin('appointments', 'appointments.id', '=', 'journeys.appointment_id')
            ->selectRaw('journeys.id, journeys.source, journeys.status, journeys.arrived_at, journeys.completed_at, journeys.aborted_at, COALESCE(journeys.branch_id, appointments.branch_id) as report_branch_id');
        $this->filterJourneys($base, $request);

        $journeys = $connection->query()->fromSub($base, 'facts');
        $request->applyWindow($journeys, 'report_branch_id', 'arrived_at');

        $windows = $this->windows($request);
        $status = [];
        $sources = [];
        $daily = [];
        $hourly = [];
        $when = [];

        foreach ($journeys->orderBy('arrived_at')->cursor() as $row) {
            $branchId = (int) $row->report_branch_id;
            $state = (string) $row->status;
            $source = (string) $row->source;
            $local = CarbonImmutable::parse((string) $row->arrived_at, 'UTC')
                ->setTimezone($windows[$branchId]->timezone);
            $date = $local->toDateString();
            $hour = $local->format('Y-m-d H');

            $when[(int) $row->id] = [$date, $hour];
            $status[$state] = ($status[$state] ?? 0) + 1;
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $daily[$date] = ($daily[$date] ?? 0) + 1;
            $hourly[$hour] = ($hourly[$hour] ?? 0) + 1;
        }

        ksort($daily);
        ksort($hourly);

        // The same windowed visits as a subquery, never a bound id list: a
        // year of a busy center would exceed the placeholder limit.
        $visitIds = $connection->query()->fromSub($base, 'visit_ids')->select('visit_ids.id');
        $request->applyWindow($visitIds, 'visit_ids.report_branch_id', 'visit_ids.arrived_at');

        $stages = $when === [] ? [] : $this->stages($connection, $when, $visitIds, $request);

        return [
            'total' => count($when),
            'status' => $status,
            'sources' => $sources,
            'daily' => $daily,
            'hourly' => $hourly,
            'stages' => $stages,
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $when  journey id => [local date, local hour]
     * @param  Builder  $visitIds  the same visits, as a subquery of their ids
     * @return array<string, mixed>
     */
    private function stages(Connection $connection, array $when, Builder $visitIds, ReportReadRequest $request): array
    {
        $query = $connection->table('journey_stages as stages')
            ->leftJoin('appointment_items as items', 'items.id', '=', 'stages.appointment_item_id')
            ->leftJoin('services', 'services.id', '=', 'items.service_id')
            ->leftJoin('employees', 'employees.id', '=', 'stages.employee_id')
            ->whereIn('stages.service_journey_id', $visitIds)
            ->leftJoin('services as walk_in_services', 'walk_in_services.id', '=', 'stages.service_id')
            ->selectRaw('stages.service_journey_id, stages.status, stages.employee_id, employees.name as employee_name, COALESCE(stages.service_id, items.service_id) as service_id, COALESCE(stages.service_name, services.name, walk_in_services.name) as service_name, stages.service_started_at, stages.service_completed_at');

        // A filtered report attributes only the matching stages: "Sara's
        // work" is her stages, not every stage of a visit she touched.
        $employee = $request->filter('employee');

        if (is_string($employee) && $employee !== '') {
            $query->where('employees.uuid', $employee);
        }

        $service = $request->filter('service');

        if (is_string($service) && $service !== '') {
            $query->whereRaw('COALESCE(stages.service_id, items.service_id) = (SELECT filter_services.id FROM services AS filter_services WHERE filter_services.uuid = ? LIMIT 1)', [$service]);
        }

        $status = [];
        $services = [];
        $employees = [];
        $daily = [];
        $hourly = [];
        $completed = 0;
        $minutes = 0;

        foreach ($query->cursor() as $row) {
            $state = (string) $row->status;
            $status[$state] = ($status[$state] ?? 0) + 1;

            if ($state !== 'completed') {
                continue;
            }

            $completed++;
            $duration = $row->service_started_at !== null && $row->service_completed_at !== null
                ? max(0, (int) CarbonImmutable::parse((string) $row->service_started_at, 'UTC')->diffInMinutes(CarbonImmutable::parse((string) $row->service_completed_at, 'UTC')))
                : 0;
            $minutes += $duration;

            [$date, $hour] = $when[(int) $row->service_journey_id] ?? [null, null];

            if ($date !== null && $hour !== null) {
                $daily[$date] = ($daily[$date] ?? 0) + 1;
                $hourly[$hour] = ($hourly[$hour] ?? 0) + 1;
            }

            $serviceId = (int) $row->service_id;
            $employeeId = (int) $row->employee_id;

            if ($serviceId > 0) {
                $services[$serviceId] ??= ['name' => $this->translated($row->service_name), 'completed' => 0, 'minutes' => 0];
                $services[$serviceId]['completed']++;
                $services[$serviceId]['minutes'] += $duration;
            }

            if ($employeeId > 0) {
                $employees[$employeeId] ??= ['name' => $this->translated($row->employee_name), 'completed' => 0, 'minutes' => 0];
                $employees[$employeeId]['completed']++;
                $employees[$employeeId]['minutes'] += $duration;
            }
        }

        ksort($daily);
        ksort($hourly);

        return compact('status', 'services', 'employees', 'completed', 'minutes', 'daily', 'hourly');
    }

    /**
     * Optional narrowing by the ACTUAL performer or the performed service
     * (uuids resolved in SQL): a visit counts when one of its stages matches.
     */
    private function filterJourneys(Builder $journeys, ReportReadRequest $request): void
    {
        $employee = $request->filter('employee');

        if (is_string($employee) && $employee !== '') {
            $journeys->whereExists(static fn (Builder $stages) => $stages->selectRaw('1')
                ->from('journey_stages as filter_stages')
                ->join('employees as filter_employees', 'filter_employees.id', '=', 'filter_stages.employee_id')
                ->whereColumn('filter_stages.service_journey_id', 'journeys.id')
                ->where('filter_employees.uuid', $employee));
        }

        $service = $request->filter('service');

        if (is_string($service) && $service !== '') {
            $journeys->whereExists(static fn (Builder $stages) => $stages->selectRaw('1')
                ->from('journey_stages as filter_stages')
                ->leftJoin('appointment_items as filter_items', 'filter_items.id', '=', 'filter_stages.appointment_item_id')
                ->whereColumn('filter_stages.service_journey_id', 'journeys.id')
                ->whereRaw('COALESCE(filter_stages.service_id, filter_items.service_id) = (SELECT filter_services.id FROM services AS filter_services WHERE filter_services.uuid = ? LIMIT 1)', [$service]));
        }
    }

    private function translated(mixed $json): string
    {
        $values = json_decode((string) $json, true);
        $values = is_array($values) ? $values : [];

        return (string) ($values[app()->getLocale()] ?? $values['en'] ?? reset($values) ?: '—');
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
}
