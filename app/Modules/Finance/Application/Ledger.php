<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Enums\EntrySource;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only writer of `finance_entries`.
 *
 * Runs inside the transaction that made the money move — a payment succeeding,
 * a refund succeeding, an expense being posted or voided — and throws outside
 * one, because an entry that could commit without its fact, or a fact without
 * its entry, is the split brain the ledger exists to rule out
 * (docs/20-FINANCE.md §37).
 *
 * Idempotent per source: a second append for the same source and kind finds the
 * first and returns it. `unique(source_type, source_uuid, kind)` backs that
 * against a race.
 */
final class Ledger
{
    public function append(
        EntryKind $kind,
        EntrySource $source,
        string $sourceUuid,
        int $branchId,
        int $amountMinor,
        string $currency,
        PaymentMethod $method,
        ?string $provider,
        ?int $cashierShiftId,
        string $label,
        CarbonInterface $occurredAt,
    ): FinanceEntry {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('Ledger::append() must run inside the transaction that moved the money.');
        }

        if ($amountMinor <= 0) {
            throw new RuntimeException('A ledger entry records a positive amount; its direction says which way it moved.');
        }

        /** @var FinanceEntry|null $existing */
        $existing = FinanceEntry::query()
            ->where('source_type', $source->value)
            ->where('source_uuid', $sourceUuid)
            ->where('kind', $kind->value)
            ->first();

        if ($existing instanceof FinanceEntry) {
            return $existing;
        }

        /** @var FinanceEntry $entry */
        $entry = FinanceEntry::query()->create([
            'branch_id' => $branchId,
            'direction' => $kind->direction(),
            'kind' => $kind,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'method' => $method,
            'provider' => $provider,
            'source_type' => $source,
            'source_uuid' => $sourceUuid,
            'cashier_shift_id' => $cashierShiftId,
            'label' => mb_substr($label, 0, 190),
            'occurred_at' => $occurredAt,
            'created_at' => now()->utc(),
        ]);

        return $entry;
    }
}
