<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application\Actions;

use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Modules\Finance\Application\ExpectedCash;
use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FinanceAudit;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Closes a cashier shift with the drawer counted.
 *
 *     BEGIN
 *       lock the shift                         → must be open
 *       expected = ExpectedCash, from the ledger, under that lock
 *       variance = counted − expected          (recorded; never "corrected")
 *       write the reconciliation, with every component snapshotted
 *       close the shift                        (Sales' own close, in THIS transaction)
 *       audit
 *     COMMIT
 *
 * The shift lock is the one every cash collection, cash refund and drawer
 * expense takes, so no money can enter or leave the drawer between computing
 * what it should hold and closing it (docs/20-FINANCE.md §33).
 *
 * A nonzero variance never blocks the close — it is the fact being recorded.
 * No note is demanded: no such policy exists, and inventing one is not this
 * Action's call.
 *
 * Your own shift needs `cashier_shift.manage`; somebody else's, `supervise`.
 * Plus `finance`. A center without Finance closes shifts the Phase 9 way.
 */
final class CloseShiftWithCount
{
    public function __construct(
        private readonly FinanceAccess $access,
        private readonly ExpectedCash $expected,
        private readonly ManageCashierShift $shifts,
        private readonly FinanceAudit $audit,
    ) {}

    /**
     * @throws FinanceFailed
     * @throws AuthorizationException
     */
    public function __invoke(CashierShift $shift, User $actingUser, int $countedCashMinor, ?string $note = null, ?CarbonImmutable $now = null): ShiftReconciliation
    {
        $permission = $shift->user_id === $actingUser->getKey()
            ? Permission::CashierShiftManage
            : Permission::CashierShiftSupervise;

        $this->access->ensure($actingUser, $permission, $shift->branch_id, 'You may not close that cashier shift.');

        if ($countedCashMinor < 0 || $countedCashMinor > ManageCashierShift::MAX_DRAWER_MINOR) {
            throw FinanceFailed::policy('The counted cash must be zero or more, and within a drawer range.');
        }

        $note = $note === null ? null : trim($note);
        $note = $note === null || $note === '' ? null : mb_substr($note, 0, 190);
        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var ShiftReconciliation $reconciliation */
        $reconciliation = DB::connection('tenant')->transaction(function () use ($shift, $actingUser, $countedCashMinor, $note, $at): ShiftReconciliation {
            /** @var CashierShift|null $locked */
            $locked = CashierShift::query()->whereKey($shift->getKey())->lockForUpdate()->first();

            if (! $locked instanceof CashierShift || $locked->status !== ShiftStatus::Open) {
                throw FinanceFailed::invalidTransition('That shift is already closed.');
            }

            $figures = $this->expected->forShift($locked);

            /** @var ShiftReconciliation $reconciliation */
            $reconciliation = ShiftReconciliation::query()->create([
                'cashier_shift_id' => $locked->getKey(),
                'branch_id' => $locked->branch_id,
                'currency' => Currency::default()->value,
                'opening_cash_minor' => $figures['opening'],
                'cash_collected_minor' => $figures['collected'],
                'cash_refunded_minor' => $figures['refunded'],
                'cash_expenses_minor' => $figures['expenses'],
                'cash_expense_reversals_minor' => $figures['reversals'],
                'expected_cash_minor' => $figures['expected'],
                'counted_cash_minor' => $countedCashMinor,
                'variance_minor' => $countedCashMinor - $figures['expected'],
                'note' => $note,
                'reconciled_by_id' => $actingUser->uuid,
                'reconciled_by_label' => $actingUser->name,
                'reconciled_at' => $at,
            ]);

            // Sales closes its own shift — nested, so it commits with the count.
            $this->shifts->close($locked, $actingUser, $note, $at);

            $this->audit->record('cashier_shift.reconciled', $actingUser, $reconciliation, $reconciliation->uuid,
                after: [
                    'expected_cash_minor' => $figures['expected'],
                    'counted_cash_minor' => $countedCashMinor,
                    'variance_minor' => $countedCashMinor - $figures['expected'],
                ],
                meta: ['shift' => $locked->uuid, 'on_behalf' => $locked->user_id !== $actingUser->getKey()],
                severity: $countedCashMinor === $figures['expected'] ? AuditSeverity::Info : AuditSeverity::Warning,
            );

            return $reconciliation;
        });

        return $reconciliation;
    }
}
