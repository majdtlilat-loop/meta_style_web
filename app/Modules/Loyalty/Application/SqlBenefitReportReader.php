<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Loyalty\Contracts\BenefitReportReader;
use Carbon\CarbonImmutable;

final readonly class SqlBenefitReportReader implements BenefitReportReader
{
    public function __construct(private ReportConnection $connections) {}

    public function summary(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);

        $packages = $connection->table('package_transactions as movements')
            ->join('sales', 'sales.uuid', '=', 'movements.sale_uuid')
            ->selectRaw('movements.kind, SUM(movements.quantity) as quantity')
            ->groupBy('movements.kind');
        $request->applyWindow($packages, 'sales.branch_id', 'movements.occurred_at');

        $memberships = $connection->table('membership_benefit_usages as usages')
            ->join('sales', 'sales.uuid', '=', 'usages.sale_uuid')
            ->selectRaw('usages.kind, SUM(usages.quantity) as quantity')
            ->groupBy('usages.kind');
        $request->applyWindow($memberships, 'sales.branch_id', 'usages.occurred_at');

        $loyalty = $connection->table('loyalty_transactions as movements')
            ->join('invoices', 'invoices.uuid', '=', 'movements.context_uuid')
            ->selectRaw('movements.kind, movements.direction, SUM(movements.points) as points')
            ->groupBy('movements.kind', 'movements.direction');
        $request->applyWindow($loyalty, 'invoices.branch_id', 'movements.occurred_at');

        // Sold and activated in the period, by the branch that sold them.
        $membershipActivations = $connection->table('customer_memberships');
        $request->applyWindow($membershipActivations, 'branch_id', 'activated_at');
        $packageActivations = $connection->table('customer_packages');
        $request->applyWindow($packageActivations, 'branch_id', 'activated_at');

        return [
            'daily_points' => $this->dailyPoints($request),
            'activations' => [
                'memberships' => $membershipActivations->count(),
                'packages' => $packageActivations->count(),
            ],
            'packages' => $packages->pluck('quantity', 'kind')->map(static fn (mixed $value): int => (int) $value)->all(),
            'memberships' => $memberships->pluck('quantity', 'kind')->map(static fn (mixed $value): int => (int) $value)->all(),
            'loyalty' => $loyalty->get()->map(static fn (object $row): array => [
                'kind' => (string) $row->kind,
                'direction' => (string) $row->direction,
                'points' => (int) $row->points,
            ])->all(),
            'coverage' => 'Branch-scoped movements linked to a sale or invoice; center-wide manual movements are excluded.',
        ];
    }

    /**
     * Loyalty points in and out per branch-local day, over the same
     * invoice-linked movements as the totals.
     *
     * @return array{in: array<string, int>, out: array<string, int>}
     */
    private function dailyPoints(ReportReadRequest $request): array
    {
        $movements = $this->connections->for($request->target)->table('loyalty_transactions as movements')
            ->join('invoices', 'invoices.uuid', '=', 'movements.context_uuid')
            ->select(['invoices.branch_id', 'movements.occurred_at', 'movements.direction', 'movements.points']);
        $request->applyWindow($movements, 'invoices.branch_id', 'movements.occurred_at');

        $timezones = [];

        foreach ($request->windows as $window) {
            $timezones[$window->branchId] = $window->timezone;
        }

        $daily = ['in' => [], 'out' => []];

        foreach ($movements->orderBy('movements.occurred_at')->cursor() as $row) {
            $direction = (string) $row->direction === 'in' ? 'in' : 'out';
            $date = CarbonImmutable::parse((string) $row->occurred_at, 'UTC')
                ->setTimezone($timezones[(int) $row->branch_id] ?? 'UTC')
                ->toDateString();
            $daily[$direction][$date] = ($daily[$direction][$date] ?? 0) + (int) $row->points;
        }

        ksort($daily['in']);
        ksort($daily['out']);

        return $daily;
    }
}
