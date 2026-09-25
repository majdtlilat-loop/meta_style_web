<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Payments\Contracts\PaymentReportReader;
use Carbon\CarbonImmutable;

final readonly class SqlPaymentReportReader implements PaymentReportReader
{
    public function __construct(private ReportConnection $connections) {}

    public function summary(ReportReadRequest $request): array
    {
        return $this->totals($request) + [
            'outstanding' => $this->outstanding($request),
            'customer_values' => $this->customerValues($request),
        ];
    }

    public function totals(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);
        $payments = $connection->table('payments')
            ->where('status', 'succeeded')
            ->select(['branch_id', 'succeeded_at', 'currency', 'method', 'amount_minor']);
        $request->applyWindow($payments, 'branch_id', 'succeeded_at');

        $refunds = $connection->table('refunds')
            ->where('status', 'succeeded')
            ->select(['branch_id', 'succeeded_at', 'currency', 'amount_minor']);
        $request->applyWindow($refunds, 'branch_id', 'succeeded_at');

        $windows = [];

        foreach ($request->windows as $window) {
            $windows[$window->branchId] = $window->timezone;
        }

        $collected = [];
        $refunded = [];
        $methods = [];
        $daily = [];
        $hourly = [];

        /*
         * One row per movement, placed on the branch-local day and hour it
         * happened: net per bucket is collections less refunds in THAT bucket,
         * the same cash-flow definition as the period total.
         */
        foreach ($payments->orderBy('succeeded_at')->cursor() as $row) {
            $currency = (string) $row->currency;
            $amount = (int) $row->amount_minor;
            [$date, $hour] = $this->local((string) $row->succeeded_at, $windows[(int) $row->branch_id] ?? 'UTC');

            $collected[$currency] = ($collected[$currency] ?? 0) + $amount;
            $methods[(string) $row->method][$currency] = ($methods[(string) $row->method][$currency] ?? 0) + $amount;
            $daily[$currency][$date] = ($daily[$currency][$date] ?? 0) + $amount;
            $hourly[$currency][$hour] = ($hourly[$currency][$hour] ?? 0) + $amount;
        }

        foreach ($refunds->orderBy('succeeded_at')->cursor() as $row) {
            $currency = (string) $row->currency;
            $amount = (int) $row->amount_minor;
            [$date, $hour] = $this->local((string) $row->succeeded_at, $windows[(int) $row->branch_id] ?? 'UTC');

            $refunded[$currency] = ($refunded[$currency] ?? 0) + $amount;
            $daily[$currency][$date] = ($daily[$currency][$date] ?? 0) - $amount;
            $hourly[$currency][$hour] = ($hourly[$currency][$hour] ?? 0) - $amount;
        }

        $net = [];

        foreach (array_unique([...array_keys($collected), ...array_keys($refunded)]) as $currency) {
            $net[$currency] = ($collected[$currency] ?? 0) - ($refunded[$currency] ?? 0);
        }

        foreach ($daily as $currency => $days) {
            ksort($days);
            $daily[$currency] = $days;
        }

        foreach ($hourly as $currency => $hours) {
            ksort($hours);
            $hourly[$currency] = $hours;
        }

        return compact('collected', 'refunded', 'net', 'methods', 'daily', 'hourly');
    }

    /** @return array{0: string, 1: string} */
    private function local(string $utc, string $timezone): array
    {
        $local = CarbonImmutable::parse($utc, 'UTC')->setTimezone($timezone);

        return [$local->toDateString(), $local->format('Y-m-d H')];
    }

    /** @return array<string, int> */
    private function outstanding(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);

        /*
         * Select invoices by issue date, then settle each one over its whole
         * lifetime. Filtering the joined payments by report date would mix a
         * period cash-flow metric with an invoice-balance metric.
         */
        $balances = $connection->table('invoices')
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->leftJoin('payments', function ($join): void {
                $join->on('payments.invoice_id', '=', 'invoices.id')
                    ->where('payments.status', '=', 'succeeded');
            })
            ->where('sales.status', '!=', 'voided')
            ->selectRaw('invoices.id, invoices.currency, invoices.grand_total_minor, COALESCE(SUM(payments.amount_minor), 0) as settled_minor')
            ->groupBy('invoices.id', 'invoices.currency', 'invoices.grand_total_minor');
        $request->applyWindow($balances, 'invoices.branch_id', 'invoices.issued_at');

        $outstanding = [];

        foreach ($balances->get() as $balance) {
            $currency = (string) $balance->currency;
            $outstanding[$currency] = ($outstanding[$currency] ?? 0)
                + max(0, (int) $balance->grand_total_minor - (int) $balance->settled_minor);
        }

        return $outstanding;
    }

    /** @return list<array{rank: int, currency: string, collected_minor: int, refunded_minor: int, net_minor: int}> */
    private function customerValues(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);
        $payments = $connection->table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->where('payments.status', 'succeeded')
            ->whereNotNull('sales.customer_id')
            ->selectRaw('sales.customer_id, payments.currency, SUM(payments.amount_minor) as total_minor')
            ->groupBy('sales.customer_id', 'payments.currency');
        $request->applyWindow($payments, 'payments.branch_id', 'payments.succeeded_at');

        $refunds = $connection->table('refunds')
            ->join('payments', 'payments.id', '=', 'refunds.payment_id')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->where('refunds.status', 'succeeded')
            ->whereNotNull('sales.customer_id')
            ->selectRaw('sales.customer_id, refunds.currency, SUM(refunds.amount_minor) as total_minor')
            ->groupBy('sales.customer_id', 'refunds.currency');
        $request->applyWindow($refunds, 'refunds.branch_id', 'refunds.succeeded_at');

        $values = [];

        foreach ($payments->get() as $row) {
            $key = (int) $row->customer_id.'|'.(string) $row->currency;
            $values[$key] = ['currency' => (string) $row->currency, 'collected_minor' => (int) $row->total_minor, 'refunded_minor' => 0];
        }

        foreach ($refunds->get() as $row) {
            $key = (int) $row->customer_id.'|'.(string) $row->currency;
            $values[$key] ??= ['currency' => (string) $row->currency, 'collected_minor' => 0, 'refunded_minor' => 0];
            $values[$key]['refunded_minor'] += (int) $row->total_minor;
        }

        $rows = array_map(static fn (array $row): array => $row + [
            'net_minor' => $row['collected_minor'] - $row['refunded_minor'],
        ], array_values($values));
        usort($rows, static fn (array $a, array $b): int => $b['net_minor'] <=> $a['net_minor']);

        return array_map(static fn (array $row, int $index): array => ['rank' => $index + 1] + $row, array_slice($rows, 0, 50), array_keys(array_slice($rows, 0, 50)));
    }
}
