<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Payments\Contracts\PaymentReportReader;
use App\Modules\Queue\Contracts\QueueReportReader;
use App\Modules\Reports\Application\ReportsAccess;
use App\Modules\Reports\Application\StandardReports;
use App\Modules\Reports\Data\ReportKpi;
use App\Modules\Reports\Data\ReportResult;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class AdvancedReports
{
    public function __construct(
        private ReportsAccess $access,
        private AdvancedReportCatalog $catalog,
        private StandardReports $standard,
        private PaymentReportReader $payments,
        private QueueReportReader $queue,
    ) {}

    /**
     * @param  ReportReadRequest|null  $comparison  the period to compare with.
     *                                              Only its DATES are used: the
     *                                              branches are always the
     *                                              current request's. Null is
     *                                              the equal, immediately
     *                                              preceding period.
     */
    public function run(string $code, User $user, ReportReadRequest $current, ?ReportReadRequest $comparison = null): ReportResult
    {
        $this->access->advanced($user);
        $definition = $this->catalog->find($code);

        if ($definition === null) {
            throw new InvalidArgumentException('Unknown Advanced Report.');
        }

        foreach ($definition['permissions'] as $permissionCode) {
            $permission = Permission::tryFrom($permissionCode);

            if (! $permission instanceof Permission || ! $user->hasPermission($permission)) {
                throw new AuthorizationException('You may not view the source data required by this Advanced Report.');
            }
        }

        $previous = $comparison === null
            ? PeriodWindows::previous($current)
            : PeriodWindows::shift($current, $comparison->fromDate, $comparison->toDate);

        return match ($code) {
            'period_comparison' => $this->compareStandard($code, $definition, 'business_overview', $user, $current, $previous),
            'branch_comparison' => $this->branches($definition, $user, $current),
            'service_analysis' => $this->compareDimensions($code, $definition, 'visit_service_delivery', $user, $current, $previous, 'name'),
            'employee_analysis' => $this->compareDimensions($code, $definition, 'employee_delivery', $user, $current, $previous, 'name'),
            'booking_funnel_analysis' => $this->funnel($definition, $user, $current),
            'customer_cohorts' => $this->compareStandard($code, $definition, 'customer_activity', $user, $current, $previous),
            'customer_retention' => $this->retention($definition, $user, $current, $previous),
            'customer_value' => $this->customerValue($definition, $current),
            'queue_trends' => $this->queueTrends($definition, $current),
            'benefit_trends' => $this->compareStandard($code, $definition, 'benefit_usage', $user, $current, $previous),
            default => throw new InvalidArgumentException('Unknown Advanced Report.'),
        };
    }

    /** @param array{title: string, description: string, permissions: list<string>} $definition */
    private function compareStandard(string $code, array $definition, string $standardCode, User $user, ReportReadRequest $current, ReportReadRequest $previous): ReportResult
    {
        $now = $this->standard->run($standardCode, $user, $current);
        $before = $this->standard->run($standardCode, $user, $previous);
        $currentKpis = $this->kpis($now);
        $previousKpis = $this->kpis($before);
        $rows = [];

        foreach ($currentKpis as $key => $kpi) {
            $prior = $previousKpis[$key]['value'] ?? null;
            $rows[] = [
                'metric_key' => (string) $key,
                'metric' => $kpi['label'],
                'current' => $kpi['value'],
                'previous' => $prior,
                'change_percent' => $this->percent($kpi['value'], $prior),
                'format' => $kpi['format'],
            ];
        }

        return $this->result($code, $definition, $now->kpis, $now->series, $rows, $current, $now->currency, [
            'comparison' => $this->comparisonText($previous),
        ], ['current_sample' => count($now->rows), 'previous_sample' => count($before->rows), 'comparison_from' => $previous->fromDate, 'comparison_to' => $previous->toDate], $now->unavailable);
    }

    /** @param array{title: string, description: string, permissions: list<string>} $definition */
    private function branches(array $definition, User $user, ReportReadRequest $current): ReportResult
    {
        $rows = [];

        foreach ($current->windows as $window) {
            $request = new ReportReadRequest($current->target, $current->fromDate, $current->toDate, [$window], $current->filters);
            $report = $this->standard->run('business_overview', $user, $request);
            $kpis = $this->kpis($report);
            $rows[] = [
                'branch' => $window->branchName,
                'scheduled_bookings' => $kpis['scheduled_bookings']['value'] ?? 0,
                'completed_visits' => $kpis['completed_visits']['value'] ?? 0,
                'walk_ins' => $kpis['walk_ins']['value'] ?? 0,
                'billed_minor' => $kpis['billed']['value'] ?? null,
                'net_collected_minor' => $kpis['net_collected']['value'] ?? null,
                'currency' => $report->currency,
            ];
        }

        return $this->result('branch_comparison', $definition, [
            new ReportKpi('branches', 'Branches compared', count($rows)),
        ], [], $rows, $current, null, [
            'branch_comparison' => 'Each branch uses the same selected local dates converted through that branch current timezone.',
        ], ['branches' => count($rows)], ['ranking' => 'A ranking is intentionally not calculated; totals have different opportunity and sample sizes.']);
    }

    /** @param array{title: string, description: string, permissions: list<string>} $definition */
    private function compareDimensions(string $code, array $definition, string $standardCode, User $user, ReportReadRequest $current, ReportReadRequest $previous, string $key): ReportResult
    {
        $now = $this->standard->run($standardCode, $user, $current);
        $before = $this->standard->run($standardCode, $user, $previous);
        $old = [];

        foreach ($before->rows as $row) {
            $old[(string) ($row[$key] ?? '')] = $row;
        }

        $rows = [];

        foreach ($now->rows as $row) {
            $name = (string) ($row[$key] ?? '—');
            $prior = $old[$name] ?? [];
            $rows[] = $row + [
                'previous_completed' => (int) ($prior['completed'] ?? 0),
                'completed_change_percent' => $this->percent($row['completed'] ?? null, $prior['completed'] ?? null),
                'previous_minutes' => (int) ($prior['minutes'] ?? 0),
            ];
        }

        return $this->result($code, $definition, $now->kpis, [], $rows, $current, null, [
            'actual_performer' => 'Employee analysis attributes completed work to the JourneyStage employee.',
            'comparison' => $this->comparisonText($previous),
        ], ['current_rows' => count($now->rows), 'previous_rows' => count($before->rows), 'comparison_from' => $previous->fromDate, 'comparison_to' => $previous->toDate], $now->unavailable);
    }

    /** @param array{title: string, description: string, permissions: list<string>} $definition */
    private function funnel(array $definition, User $user, ReportReadRequest $current): ReportResult
    {
        $bookings = $this->standard->run('booking_activity', $user, $current);
        $visits = $this->standard->run('visit_service_delivery', $user, $current);
        $bookingKpis = $this->kpis($bookings);
        $visitKpis = $this->kpis($visits);
        $scheduled = (int) ($bookingKpis['scheduled']['value'] ?? 0);
        $arrivals = (int) ($visitKpis['arrivals']['value'] ?? 0);
        $completed = (int) ($visitKpis['completed_visits']['value'] ?? 0);

        return $this->result('booking_funnel_analysis', $definition, [
            new ReportKpi('scheduled', 'Scheduled bookings', $scheduled),
            new ReportKpi('arrivals', 'Arrivals', $arrivals),
            new ReportKpi('completed', 'Completed visits', $completed),
            new ReportKpi('arrival_rate', 'Booking to arrival', $scheduled > 0 ? round(($arrivals / $scheduled) * 100, 1) : null, 'percent'),
            new ReportKpi('completion_rate', 'Arrival to completed', $arrivals > 0 ? round(($completed / $arrivals) * 100, 1) : null, 'percent'),
        ], [], [
            ['stage_key' => 'scheduled', 'stage' => 'Scheduled bookings', 'count' => $scheduled, 'denominator' => $scheduled],
            ['stage_key' => 'arrivals', 'stage' => 'Arrivals', 'count' => $arrivals, 'denominator' => $scheduled],
            ['stage_key' => 'completed', 'stage' => 'Completed visits', 'count' => $completed, 'denominator' => $arrivals],
        ], $current, null, [
            'arrival_rate' => 'Arrivals divided by scheduled bookings in the selected range.',
            'completion_rate' => 'Completed visits divided by arrivals in the selected range.',
        ], ['scheduled' => $scheduled, 'arrivals' => $arrivals]);
    }

    /** @param array{title: string, description: string, permissions: list<string>} $definition */
    private function retention(array $definition, User $user, ReportReadRequest $current, ReportReadRequest $previous): ReportResult
    {
        $now = $this->standard->run('customer_activity', $user, $current);
        $before = $this->standard->run('customer_activity', $user, $previous);
        $n = $this->kpis($now);
        $p = $this->kpis($before);
        $customers = (int) ($n['customers']['value'] ?? 0);
        $returning = (int) ($n['returning']['value'] ?? 0);
        $priorCustomers = (int) ($p['customers']['value'] ?? 0);
        $priorReturning = (int) ($p['returning']['value'] ?? 0);

        return $this->result('customer_retention', $definition, [
            new ReportKpi('return_rate', 'Observed return rate', $customers > 0 ? round($returning / $customers * 100, 1) : null, 'percent'),
            new ReportKpi('previous_return_rate', 'Previous return rate', $priorCustomers > 0 ? round($priorReturning / $priorCustomers * 100, 1) : null, 'percent'),
            new ReportKpi('customers', 'Current sample', $customers),
        ], [], [], $current, null, [
            'return_rate' => 'Customers served in the period who had an earlier observed visit, divided by customers served.',
        ], ['current_customers' => $customers, 'previous_customers' => $priorCustomers, 'comparison_from' => $previous->fromDate, 'comparison_to' => $previous->toDate], ['predictive_retention' => 'This is observed return behavior, not predictive retention.']);
    }

    /** @param array{title: string, description: string, permissions: list<string>} $definition */
    private function customerValue(array $definition, ReportReadRequest $current): ReportResult
    {
        $data = $this->payments->summary($current);
        $rows = $data['customer_values'];
        $total = array_sum(array_column($rows, 'net_minor'));

        return $this->result('customer_value', $definition, [
            new ReportKpi('customers', 'Customers with collection', count($rows)),
            new ReportKpi('net_collected', 'Net collected in shown sample', $total, 'money_minor'),
        ], [], $rows, $current, count(array_unique(array_column($rows, 'currency'))) === 1 ? ($rows[0]['currency'] ?? null) : null, [
            'customer_value' => 'Succeeded payments less succeeded refunds, anonymized and ranked. No contact data is included.',
        ], ['customers_shown' => count($rows)], ['lifetime_value' => 'Billed lifetime value outside the selected period and predictive value are not calculated.']);
    }

    /** @param array{title: string, description: string, permissions: list<string>} $definition */
    private function queueTrends(array $definition, ReportReadRequest $current): ReportResult
    {
        $data = $this->queue->summary($current);
        $series = array_map(static fn (array $row): array => ['date' => $row['date'], 'value' => $row['tickets']], $data['daily']);

        return $this->result('queue_trends', $definition, [
            new ReportKpi('tickets', 'Tickets', $data['total']),
            new ReportKpi('first_call', 'Average first call', $data['average_first_call_seconds'], 'duration_seconds'),
            new ReportKpi('service_start', 'Average time to service', $data['average_service_start_seconds'], 'duration_seconds'),
        ], $series, $data['daily'], $current, null, [
            'daily_wait' => 'Observed daily means include only tickets carrying the relevant timestamp.',
        ], ['first_call_sample' => $data['first_call_sample'], 'service_start_sample' => $data['service_start_sample']], ['queue_abandonment' => 'Queue abandonment is not recorded.']);
    }

    /** The English statement of the comparison actually used (API, CSV, RAYAN). */
    private function comparisonText(ReportReadRequest $previous): string
    {
        return sprintf('Compared with %s to %s: the same branches and local-calendar dates, each in its branch timezone.', $previous->fromDate, $previous->toDate);
    }

    /** @return array<string, array<string, mixed>> */
    private function kpis(ReportResult $result): array
    {
        $indexed = [];

        foreach ($result->kpis as $kpi) {
            $indexed[$kpi->key] = $kpi->toArray();
        }

        return $indexed;
    }

    private function percent(mixed $current, mixed $previous): ?float
    {
        if (! is_numeric($current) || ! is_numeric($previous) || (float) $previous === 0.0) {
            return null;
        }

        return round((((float) $current - (float) $previous) / abs((float) $previous)) * 100, 1);
    }

    /**
     * @param  array{title: string, description: string, permissions: list<string>}  $definition
     * @param  list<ReportKpi>  $kpis
     * @param  list<array<string, int|float|string|null>>  $series
     * @param  list<array<string, int|float|string|null>>  $rows
     * @param  array<string, string>  $glossary
     * @param  array<string, int|float|string|null>  $coverage
     * @param  array<array-key, string>  $unavailable
     */
    private function result(string $code, array $definition, array $kpis, array $series, array $rows, ReportReadRequest $request, ?string $currency, array $glossary, array $coverage = [], array $unavailable = []): ReportResult
    {
        return new ReportResult($code, $definition['title'], $definition['description'], $kpis, $series, $rows, $glossary, [
            'from' => $request->fromDate,
            'to' => $request->toDate,
            'branches' => count($request->windows),
            ...$coverage,
        ], $currency, CarbonImmutable::now('UTC'), $unavailable);
    }
}
