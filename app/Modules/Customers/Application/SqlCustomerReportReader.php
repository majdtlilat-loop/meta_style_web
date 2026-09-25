<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Customers\Contracts\CustomerReportReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

final readonly class SqlCustomerReportReader implements CustomerReportReader
{
    public function __construct(private ReportConnection $connections) {}

    public function summary(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);

        /*
         * Customers are center-wide and carry no branch. "New customers" is
         * therefore derived from first observed visit per accessible branch,
         * not from the account row's creation timestamp.
         */
        $visits = $connection->query()->fromSub($this->visits($connection), 'visits');
        $request->applyWindow($visits, 'report_branch_id', 'arrived_at');

        $counts = [];
        $first = [];
        $last = [];
        $timezones = [];

        foreach ($request->windows as $window) {
            $timezones[$window->branchId] = $window->timezone;
        }

        foreach ($visits->whereNotNull('customer_id')->orderBy('arrived_at')->cursor() as $row) {
            $customer = (int) $row->customer_id;
            $counts[$customer] = ($counts[$customer] ?? 0) + 1;
            $last[$customer] = (string) $row->arrived_at;

            // The branch-local day and hour of the customer's first visit in
            // the period: where a NEW customer appears on a growth chart.
            if (! isset($first[$customer])) {
                $local = CarbonImmutable::parse((string) $row->arrived_at, 'UTC')
                    ->setTimezone($timezones[(int) $row->report_branch_id] ?? 'UTC');
                $first[$customer] = [$local->toDateString(), $local->format('Y-m-d H')];
            }
        }

        $customerIds = array_keys($counts);
        $priorCustomers = [];

        if ($customerIds !== []) {
            $prior = $connection->query()->fromSub($this->visits($connection), 'visits')
                ->whereIn('customer_id', $customerIds)
                ->where(function ($query) use ($request): void {
                    foreach ($request->windows as $window) {
                        $query->orWhere(function ($branch) use ($window): void {
                            $branch->where('report_branch_id', $window->branchId)
                                ->where('arrived_at', '<', $window->fromUtc->format('Y-m-d H:i:s'));
                        });
                    }
                })
                ->distinct()->pluck('customer_id')->all();

            $priorCustomers = array_fill_keys(array_map('intval', $prior), true);
        }

        $returning = count(array_intersect_key($counts, $priorCustomers));
        $dailyNew = [];
        $hourlyNew = [];

        foreach (array_diff_key($first, $priorCustomers) as [$date, $hour]) {
            $dailyNew[$date] = ($dailyNew[$date] ?? 0) + 1;
            $hourlyNew[$hour] = ($hourlyNew[$hour] ?? 0) + 1;
        }

        ksort($dailyNew);
        ksort($hourlyNew);

        // How many customers came once, twice, … five or more times.
        $frequency = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

        foreach ($counts as $visits) {
            $frequency[min(5, $visits)]++;
        }

        return [
            'customers' => count($counts),
            'new' => count($counts) - $returning,
            'returning' => $returning,
            'visits' => array_sum($counts),
            'visit_frequency' => $counts === [] ? null : round(array_sum($counts) / count($counts), 1),
            'daily_new' => $dailyNew,
            'hourly_new' => $hourlyNew,
            'frequency' => $frequency,
            'top' => $this->top($connection, $counts, $last, $priorCustomers),
        ];
    }

    /**
     * The ten customers with the most visits in the period: uuid and NAME
     * only (never a contact detail), their visit count, their last visit
     * (UTC) and whether they had visited before the period.
     *
     * @param  array<int, int>  $counts
     * @param  array<int, string>  $last
     * @param  array<int, bool>  $prior
     * @return list<array{uuid: string, name: string, visits: int, last_visit: string, returning: bool}>
     */
    private function top(Connection $connection, array $counts, array $last, array $prior): array
    {
        if ($counts === []) {
            return [];
        }

        uksort($counts, static fn (int $a, int $b): int => [$counts[$b], $last[$b] ?? ''] <=> [$counts[$a], $last[$a] ?? '']);
        $ids = array_slice(array_keys($counts), 0, 10);
        $rows = $connection->table('customers')->whereIn('id', $ids)->get(['id', 'uuid', 'name'])->keyBy('id')->all();
        $top = [];

        foreach ($ids as $id) {
            if (! isset($rows[$id])) {
                continue;
            }

            $top[] = [
                'uuid' => (string) $rows[$id]->uuid,
                'name' => (string) $rows[$id]->name,
                'visits' => $counts[$id],
                'last_visit' => CarbonImmutable::parse($last[$id] ?? 'now', 'UTC')->toIso8601String(),
                'returning' => isset($prior[$id]),
            ];
        }

        return $top;
    }

    private function visits(Connection $connection): Builder
    {
        $booked = $connection->table('service_journeys as journeys')
            ->join('appointments', 'appointments.id', '=', 'journeys.appointment_id')
            ->selectRaw('appointments.customer_id, appointments.branch_id as report_branch_id, journeys.arrived_at');

        return $booked->unionAll(
            $connection->table('service_journeys as journeys')
                ->where('journeys.source', 'walk_in')
                ->selectRaw('journeys.customer_id, journeys.branch_id as report_branch_id, journeys.arrived_at')
        );
    }
}
