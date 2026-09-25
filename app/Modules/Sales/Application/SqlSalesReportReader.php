<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Reporting\BranchWindow;
use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Sales\Contracts\SalesReportReader;
use Carbon\CarbonImmutable;

final readonly class SqlSalesReportReader implements SalesReportReader
{
    public function __construct(private ReportConnection $connections) {}

    public function summary(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);

        /*
         * One pass over the period's invoices: totals, the per-currency
         * invoice count an average may divide by, and the branch-local day
         * and hour each billed amount belongs to. Voided sales are counted
         * apart and never billed.
         */
        $query = $connection->table('invoices')
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->select(['invoices.branch_id', 'invoices.issued_at', 'invoices.currency', 'invoices.grand_total_minor', 'sales.status']);
        $request->applyWindow($query, 'invoices.branch_id', 'invoices.issued_at');

        $windows = $this->windows($request);
        $currencies = [];
        $invoices = 0;
        $voided = 0;
        $billed = [];
        $billedInvoices = [];
        $voidedValue = [];
        $daily = [];
        $hourly = [];
        $branches = [];
        $branchInvoices = [];

        foreach ($query->orderBy('invoices.issued_at')->cursor() as $row) {
            $currency = (string) $row->currency;
            $value = (int) $row->grand_total_minor;
            $currencies[$currency] = true;
            $invoices++;

            if ((string) $row->status === 'voided') {
                $voided++;
                $voidedValue[$currency] = ($voidedValue[$currency] ?? 0) + $value;

                continue;
            }

            $billed[$currency] = ($billed[$currency] ?? 0) + $value;
            $billedInvoices[$currency] = ($billedInvoices[$currency] ?? 0) + 1;
            $branches[(int) $row->branch_id][$currency] = ($branches[(int) $row->branch_id][$currency] ?? 0) + $value;
            $branchInvoices[(int) $row->branch_id][$currency] = ($branchInvoices[(int) $row->branch_id][$currency] ?? 0) + 1;

            $timezone = isset($windows[(int) $row->branch_id]) ? $windows[(int) $row->branch_id]->timezone : 'UTC';
            $local = CarbonImmutable::parse((string) $row->issued_at, 'UTC')->setTimezone($timezone);
            $date = $local->toDateString();
            $hour = $local->format('Y-m-d H');
            $daily[$currency][$date] = ($daily[$currency][$date] ?? 0) + $value;
            $hourly[$currency][$hour] = ($hourly[$currency][$hour] ?? 0) + $value;
        }

        foreach ($daily as $currency => $days) {
            ksort($days);
            $daily[$currency] = $days;
        }

        foreach ($hourly as $currency => $hours) {
            ksort($hours);
            $hourly[$currency] = $hours;
        }

        $items = $connection->table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->where('sales.status', '!=', 'voided')
            ->selectRaw('invoice_items.kind, invoice_items.name, invoice_items.currency, COUNT(*) as line_count, SUM(invoice_items.quantity) as quantity, SUM(invoice_items.line_total_minor) as total_minor')
            ->groupBy('invoice_items.kind', 'invoice_items.name', 'invoice_items.currency')
            ->orderByDesc('line_count')
            ->limit(50);
        $request->applyWindow($items, 'invoices.branch_id', 'invoices.issued_at');

        return [
            'invoices' => $invoices,
            'voided' => $voided,
            'billed' => $billed,
            // Non-voided invoices per currency: the only honest denominator
            // for an average ticket in that currency.
            'billed_invoices' => $billedInvoices,
            'voided_value' => $voidedValue,
            'currencies' => array_keys($currencies),
            'daily' => $daily,
            'hourly' => $hourly,
            // Billed value and non-voided invoices per branch, per currency.
            'branches' => $branches,
            'branch_invoices' => $branchInvoices,
            'items' => $items->get()->map(static fn (object $row): array => [
                'kind' => (string) $row->kind,
                'name' => self::translated($row->name),
                'currency' => (string) $row->currency,
                'count' => (int) $row->line_count,
                'total_minor' => (int) $row->total_minor,
                'quantity' => (int) $row->quantity,
            ])->all(),
            'categories' => $this->categories($request),
        ];
    }

    /**
     * Billed line value by the CUSTOMER-FACING category of the service sold
     * (`invoice_items.service_id` → `services.service_category_id`). Products,
     * custom lines and offerings have no service category and are grouped by
     * their kind with a null category, never guessed into one.
     *
     * @return list<array{category: string|null, kind: string, currency: string, count: int, total_minor: int}>
     */
    private function categories(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);
        $query = $connection->table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->join('sales', 'sales.id', '=', 'invoices.sale_id')
            ->leftJoin('services', 'services.id', '=', 'invoice_items.service_id')
            ->where('sales.status', '!=', 'voided')
            ->selectRaw('services.service_category_id as category_id, invoice_items.kind, invoice_items.currency, COUNT(*) as line_count, SUM(invoice_items.line_total_minor) as total_minor')
            ->groupBy('services.service_category_id', 'invoice_items.kind', 'invoice_items.currency')
            ->orderByDesc('total_minor')
            ->limit(50);
        $request->applyWindow($query, 'invoices.branch_id', 'invoices.issued_at');

        $rows = $query->get();
        $ids = $rows->pluck('category_id')->filter()->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();

        // Names in a second, keyed read: grouping on a JSON column is not
        // portable between MySQL and MariaDB.
        $names = $ids === [] ? [] : $connection->table('service_categories')
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->all();

        return $rows->map(static fn (object $row): array => [
            'category' => $row->category_id !== null && isset($names[(int) $row->category_id])
                ? self::translated($names[(int) $row->category_id])
                : null,
            'kind' => (string) $row->kind,
            'currency' => (string) $row->currency,
            'count' => (int) $row->line_count,
            'total_minor' => (int) $row->total_minor,
        ])->values()->all();
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
