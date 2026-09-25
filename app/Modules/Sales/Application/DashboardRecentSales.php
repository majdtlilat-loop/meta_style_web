<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Domain\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * The latest invoices issued in the viewer's branches, for the Manager
 * dashboard: number, who it was for (the name printed on the invoice), the
 * total in its own currency, and whether the sale was later voided.
 *
 * Reads need `sale.view` and the branch, like every Sales read; the
 * dashboard adds the `pos` entitlement for showing the card at all. Bounded
 * by `(branch_id, issued_at)` and a small limit — never a list of the day.
 */
final class DashboardRecentSales
{
    /**
     * @return list<array{uuid: string, number: string, customer: string|null, status: string, total_minor: int, currency: string, local_time: string, local_date: string, branch: string}>
     */
    public function latest(User $viewer, ?string $branchUuid = null, int $limit = 5): array
    {
        if (! $viewer->hasPermission(Permission::SaleView)) {
            return [];
        }

        $branches = Branch::query();
        $viewer->branchScope()->applyTo($branches, 'id');

        if (is_string($branchUuid) && $branchUuid !== '') {
            $branches->where('uuid', $branchUuid);
        }

        $ids = $branches->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        return Invoice::query()
            ->with('sale:id,status')
            ->whereIn('branch_id', $ids)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(max(1, min(20, $limit)))
            ->get()
            ->map(static function (Invoice $invoice): array {
                $local = CarbonImmutable::parse($invoice->issued_at)->setTimezone($invoice->issued_timezone !== '' ? $invoice->issued_timezone : 'UTC');

                return [
                    'uuid' => $invoice->uuid,
                    'number' => $invoice->number,
                    'customer' => $invoice->customer_name,
                    'status' => (string) ($invoice->sale?->status->value ?? 'finalized'),
                    'total_minor' => $invoice->grand_total_minor,
                    'currency' => $invoice->currency,
                    'local_time' => $local->format('H:i'),
                    'local_date' => $local->format('Y-m-d'),
                    'branch' => $invoice->branch_name->get(),
                ];
            })
            ->values()
            ->all();
    }
}
