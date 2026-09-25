<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Application;

use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The SaaS billing figures the Super Admin screens show, added up in one
 * place and per currency — amounts in different currencies are never summed
 * together. Screens format these; they never do the arithmetic themselves.
 */
final class SaasBillingTotals
{
    /** Statuses that still expect money. */
    public const OPEN = ['issued', 'partially_paid', 'overdue'];

    /**
     * What is still owed on open invoices, optionally for one center.
     *
     * @return list<array{currency: string, minor: int, invoices: int}>
     */
    public function outstanding(?string $tenantId = null): array
    {
        return SaasInvoice::query()
            ->whereIn('status', self::OPEN)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->selectRaw('currency, SUM(total_minor - paid_minor) AS minor, COUNT(*) AS invoices')
            ->groupBy('currency')
            ->toBase()->get()
            ->map(static fn (object $row): array => ['currency' => (string) $row->currency, 'minor' => (int) $row->minor, 'invoices' => (int) $row->invoices])
            ->values()->all();
    }

    /**
     * Settlements received since a moment, reversals excluded.
     *
     * @return list<array{currency: string, minor: int}>
     */
    public function collectedSince(Carbon $from): array
    {
        return DB::connection('control')->table('saas_payments')
            ->whereNull('reversed_at')
            ->where('received_at', '>=', $from)
            ->selectRaw('currency, SUM(amount_minor) AS minor')
            ->groupBy('currency')
            ->get()
            ->map(static fn (object $row): array => ['currency' => (string) $row->currency, 'minor' => (int) $row->minor])
            ->values()->all();
    }
}
