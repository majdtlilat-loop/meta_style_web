<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Finance\Contracts\FinanceReportReader;
use Carbon\CarbonImmutable;

final readonly class SqlFinanceReportReader implements FinanceReportReader
{
    public function __construct(private ReportConnection $connections) {}

    public function summary(ReportReadRequest $request): array
    {
        $query = $this->connections->for($request->target)->table('finance_entries')
            ->selectRaw('kind, direction, currency, method, COUNT(*) as entry_count, SUM(amount_minor) as total_minor')
            ->groupBy('kind', 'direction', 'currency', 'method');
        $request->applyWindow($query, 'branch_id', 'occurred_at');

        $kinds = [];
        $methods = [];
        $net = [];

        foreach ($query->get() as $row) {
            $currency = (string) $row->currency;
            $kind = (string) $row->kind;
            $method = (string) $row->method;
            $amount = (int) $row->total_minor;
            $signed = (string) $row->direction === 'in' ? $amount : -$amount;

            $kinds[$kind][$currency] = ($kinds[$kind][$currency] ?? 0) + $amount;
            $methods[$method][$currency] = ($methods[$method][$currency] ?? 0) + $signed;
            $net[$currency] = ($net[$currency] ?? 0) + $signed;
        }

        $variance = $this->variance($request);
        $daily = $this->daily($request);

        return compact('kinds', 'methods', 'net', 'variance', 'daily');
    }

    /**
     * Net movement (inflows less outflows) per currency per branch-local
     * day — the same definition as the period total, placed on the calendar.
     *
     * @return array<string, array<string, int>>
     */
    private function daily(ReportReadRequest $request): array
    {
        $query = $this->connections->for($request->target)->table('finance_entries')
            ->select(['branch_id', 'occurred_at', 'direction', 'currency', 'amount_minor']);
        $request->applyWindow($query, 'branch_id', 'occurred_at');

        $timezones = [];

        foreach ($request->windows as $window) {
            $timezones[$window->branchId] = $window->timezone;
        }

        $daily = [];

        foreach ($query->orderBy('occurred_at')->cursor() as $row) {
            $currency = (string) $row->currency;
            $date = CarbonImmutable::parse((string) $row->occurred_at, 'UTC')
                ->setTimezone($timezones[(int) $row->branch_id] ?? 'UTC')
                ->toDateString();
            $amount = (int) $row->amount_minor;
            $daily[$currency][$date] = ($daily[$currency][$date] ?? 0) + ((string) $row->direction === 'in' ? $amount : -$amount);
        }

        foreach ($daily as $currency => $days) {
            ksort($days);
            $daily[$currency] = $days;
        }

        return $daily;
    }

    /** @return array<string, int> */
    private function variance(ReportReadRequest $request): array
    {
        $query = $this->connections->for($request->target)->table('cashier_shift_reconciliations as reconciliations')
            ->join('cashier_shifts as shifts', 'shifts.id', '=', 'reconciliations.cashier_shift_id')
            ->selectRaw('reconciliations.currency, SUM(reconciliations.variance_minor) as variance_minor')
            ->groupBy('reconciliations.currency');
        $request->applyWindow($query, 'shifts.branch_id', 'reconciliations.reconciled_at');

        return $query->get()->mapWithKeys(static fn (object $row): array => [
            (string) $row->currency => (int) $row->variance_minor,
        ])->all();
    }
}
