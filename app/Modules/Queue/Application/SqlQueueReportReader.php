<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Queue\Contracts\QueueReportReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final readonly class SqlQueueReportReader implements QueueReportReader
{
    public function __construct(private ReportConnection $connections) {}

    public function summary(ReportReadRequest $request): array
    {
        $query = $this->connections->for($request->target)->table('queue_tickets')
            ->select(['branch_id', 'state', 'source', 'issued_at', 'first_called_at', 'serving_started_at', 'closed_at']);
        $request->applyWindow($query, 'branch_id', 'issued_at');
        $this->applyFilters($query, $request);

        $states = [];
        $sources = [];
        $calledSeconds = [];
        $serviceSeconds = [];
        $closed = 0;
        $total = 0;
        $daily = [];
        $hourly = [];
        $heat = [];
        $windows = [];

        foreach ($request->windows as $window) {
            $windows[$window->branchId] = $window;
        }

        foreach ($query->orderBy('issued_at')->cursor() as $row) {
            $total++;
            $state = (string) $row->state;
            $source = (string) $row->source;
            $states[$state] = ($states[$state] ?? 0) + 1;
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $local = CarbonImmutable::parse((string) $row->issued_at, 'UTC')
                ->setTimezone($windows[(int) $row->branch_id]->timezone);
            $date = $local->toDateString();
            $hour = $local->format('Y-m-d H');
            $hourly[$hour] = ($hourly[$hour] ?? 0) + 1;
            $heat[$local->dayOfWeekIso][(int) $local->format('G')] = ($heat[$local->dayOfWeekIso][(int) $local->format('G')] ?? 0) + 1;
            $daily[$date] ??= ['tickets' => 0, 'first_call_seconds' => 0, 'first_call_sample' => 0, 'service_start_seconds' => 0, 'service_start_sample' => 0];
            $daily[$date]['tickets']++;

            if ($row->first_called_at !== null) {
                $seconds = CarbonImmutable::parse((string) $row->issued_at, 'UTC')
                    ->diffInSeconds(CarbonImmutable::parse((string) $row->first_called_at, 'UTC'));
                $calledSeconds[] = $seconds;
                $daily[$date]['first_call_seconds'] += $seconds;
                $daily[$date]['first_call_sample']++;
            }

            if ($row->serving_started_at !== null) {
                $seconds = CarbonImmutable::parse((string) $row->issued_at, 'UTC')
                    ->diffInSeconds(CarbonImmutable::parse((string) $row->serving_started_at, 'UTC'));
                $serviceSeconds[] = $seconds;
                $daily[$date]['service_start_seconds'] += $seconds;
                $daily[$date]['service_start_sample']++;
            }

            if ($row->closed_at !== null) {
                $closed++;
            }
        }

        ksort($daily);
        ksort($hourly);

        return [
            'total' => $total,
            'closed' => $closed,
            'states' => $states,
            'sources' => $sources,
            'average_first_call_seconds' => $this->average($calledSeconds),
            'average_service_start_seconds' => $this->average($serviceSeconds),
            'first_call_sample' => count($calledSeconds),
            'service_start_sample' => count($serviceSeconds),
            'daily' => array_map(static fn (string $date, array $row): array => [
                'date' => $date,
                'tickets' => $row['tickets'],
                'average_first_call_seconds' => $row['first_call_sample'] > 0 ? (int) round($row['first_call_seconds'] / $row['first_call_sample']) : null,
                'average_service_start_seconds' => $row['service_start_sample'] > 0 ? (int) round($row['service_start_seconds'] / $row['service_start_sample']) : null,
            ], array_keys($daily), array_values($daily)),
            'hourly' => $hourly,
            // [ISO weekday 1–7][hour 0–23] => tickets issued, branch-local.
            'heat' => $heat,
        ];
    }

    /**
     * Optional narrowing through the ticket's stage: the employee actually
     * assigned and the service performed (uuids, resolved in SQL — an
     * unknown one matches nothing).
     */
    private function applyFilters(Builder $query, ReportReadRequest $request): void
    {
        $employee = $request->filter('employee');

        if (is_string($employee) && $employee !== '') {
            $query->whereExists(static fn (Builder $stages) => $stages->selectRaw('1')
                ->from('journey_stages as filter_stages')
                ->join('employees as filter_employees', 'filter_employees.id', '=', 'filter_stages.employee_id')
                ->whereColumn('filter_stages.id', 'queue_tickets.journey_stage_id')
                ->where('filter_employees.uuid', $employee));
        }

        $service = $request->filter('service');

        if (is_string($service) && $service !== '') {
            $query->whereExists(static fn (Builder $stages) => $stages->selectRaw('1')
                ->from('journey_stages as filter_stages')
                ->leftJoin('appointment_items as filter_items', 'filter_items.id', '=', 'filter_stages.appointment_item_id')
                ->whereColumn('filter_stages.id', 'queue_tickets.journey_stage_id')
                ->whereRaw('COALESCE(filter_stages.service_id, filter_items.service_id) = (SELECT filter_services.id FROM services AS filter_services WHERE filter_services.uuid = ? LIMIT 1)', [$service]));
        }
    }

    /** @param  list<float|int>  $values */
    private function average(array $values): ?int
    {
        return $values === [] ? null : (int) round(array_sum($values) / count($values));
    }
}
