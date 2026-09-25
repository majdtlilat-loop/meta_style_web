<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface PaymentReportReader
{
    /**
     * The full report: {@see totals()} plus `outstanding` (current unpaid
     * balance of the period's non-voided invoices) and `customer_values`
     * (anonymized, top 50).
     *
     * @return array<string, mixed>
     */
    public function summary(ReportReadRequest $request): array;

    /**
     * The period's cash movement only — `collected`, `refunded`, `net` and
     * `methods` per currency, plus `daily` and `hourly` net per currency on
     * the branch-local calendar. For surfaces (the Manager dashboard) that
     * need neither outstanding balances nor customer values.
     *
     * @return array{collected: array<string, int>, refunded: array<string, int>, net: array<string, int>, methods: array<string, array<string, int>>, daily: array<string, array<string, int>>, hourly: array<string, array<string, int>>}
     */
    public function totals(ReportReadRequest $request): array;
}
