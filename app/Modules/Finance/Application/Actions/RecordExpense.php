<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application\Actions;

use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Finance\Application\FinanceAccess;
use App\Modules\Finance\Application\FinanceAudit;
use App\Modules\Finance\Application\Ledger;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Enums\EntrySource;
use App\Modules\Finance\Domain\Enums\ExpenseStatus;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\Expense;
use App\Modules\Finance\Domain\Models\ExpenseCategory;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Posts an expense, and voids one.
 *
 * ## Post
 *
 *     BEGIN
 *       drawer cash? lock the poster's OWN open shift at the branch — explicitly
 *       write the expense, posted
 *       ledger: `expense`, money out                     (same transaction)
 *       audit
 *     COMMIT
 *
 * "Paid from the drawer" is something the person says, not something inferred:
 * a rent transfer made while a cashier happens to have a shift open must not
 * make that cashier's drawer look short (docs/20-FINANCE.md §41).
 *
 * ## Void
 *
 * Never an edit, never a delete. The expense is marked voided with who, when and
 * why, and the ledger gets an `expense_reversal` — money back in. The reversal
 * carries the drawer only while that shift is still open; a closed shift's
 * count is a snapshot and is not rewritten (§40).
 */
final class RecordExpense
{
    public const MAX_AMOUNT_MINOR = 3_000_000_000;

    public function __construct(
        private readonly FinanceAccess $access,
        private readonly Ledger $ledger,
        private readonly FinanceAudit $audit,
    ) {}

    /**
     * @param  array{branch: string, category: string, amount_minor: int, method: string, description: string, occurred_at?: CarbonImmutable|null, reference?: string|null, payee?: string|null, from_drawer?: bool, idempotency_token?: string|null}  $input
     *
     * @throws FinanceFailed
     * @throws AuthorizationException
     */
    public function post(array $input, User $actingUser, ?CarbonImmutable $now = null): Expense
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $input['branch'])->first();

        if (! $branch instanceof Branch) {
            throw new NotFoundHttpException;
        }

        $this->access->ensure($actingUser, Permission::ExpenseManage, $branch->id, 'You may not record expenses.');

        /** @var ExpenseCategory|null $category */
        $category = ExpenseCategory::query()->where('uuid', $input['category'])->first();

        if (! $category instanceof ExpenseCategory || $category->archived_at !== null) {
            throw FinanceFailed::policy('Choose an active expense category.');
        }

        $method = PaymentMethod::tryFrom($input['method']);

        if ($method === null || $method === PaymentMethod::Gateway) {
            throw FinanceFailed::policy('An expense is paid in cash or by a manual transfer.');
        }

        $amount = $input['amount_minor'];

        if ($amount <= 0 || $amount > self::MAX_AMOUNT_MINOR) {
            throw FinanceFailed::policy('An expense must be more than zero and within range.');
        }

        $description = trim($input['description']);

        if (mb_strlen($description) < 3 || mb_strlen($description) > 500) {
            throw FinanceFailed::policy('Describe the expense in 3 to 500 characters.');
        }

        $fromDrawer = (bool) ($input['from_drawer'] ?? false);

        if ($fromDrawer && $method !== PaymentMethod::Cash) {
            throw FinanceFailed::policy('Only a cash expense can come out of the drawer.');
        }

        $reference = self::optional($input['reference'] ?? null, 120);
        $payee = self::optional($input['payee'] ?? null, 120);
        $token = self::token($input['idempotency_token'] ?? null);

        if ($token !== null && ($existing = Expense::query()->where('idempotency_token', $token)->first()) instanceof Expense) {
            return $existing;
        }

        $at = ($now ?? CarbonImmutable::now())->utc();
        $occurredAt = ($input['occurred_at'] ?? null)?->utc() ?? $at;

        if ($occurredAt->greaterThan($at->addMinutes(5))) {
            throw FinanceFailed::policy('An expense cannot be dated in the future.');
        }

        try {
            /** @var Expense $expense */
            $expense = DB::connection('tenant')->transaction(function () use ($branch, $category, $method, $amount, $description, $fromDrawer, $reference, $payee, $token, $occurredAt, $actingUser): Expense {
                $shift = $fromDrawer ? $this->ownOpenShift($actingUser, $branch->id) : null;
                $currency = Currency::default()->value;

                /** @var Expense $expense */
                $expense = Expense::query()->create([
                    'branch_id' => $branch->id,
                    'expense_category_id' => $category->getKey(),
                    'amount_minor' => $amount,
                    'currency' => $currency,
                    'occurred_at' => $occurredAt,
                    'method' => $method,
                    'reference' => $reference,
                    'payee_label' => $payee,
                    'description' => $description,
                    'cashier_shift_id' => $shift?->getKey(),
                    'status' => ExpenseStatus::Posted,
                    'idempotency_token' => $token,
                    'created_by_id' => $actingUser->uuid,
                    'created_by_label' => $actingUser->name,
                ]);

                $this->ledger->append(
                    kind: EntryKind::Expense,
                    source: EntrySource::Expense,
                    sourceUuid: $expense->uuid,
                    branchId: $branch->id,
                    amountMinor: $amount,
                    currency: $currency,
                    method: $method,
                    provider: null,
                    cashierShiftId: $shift?->getKey(),
                    label: 'Expense · '.$category->name->get(app()->getLocale()),
                    occurredAt: $occurredAt,
                );

                $this->audit->record('finance.expense.posted', $actingUser, $expense, $expense->uuid,
                    after: ['amount_minor' => $amount, 'method' => $method->value, 'category' => $category->uuid],
                    meta: array_filter(['branch' => $branch->uuid, 'shift' => $shift?->uuid], static fn (mixed $value): bool => $value !== null),
                );

                return $expense;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $token === null ? null : Expense::query()->where('idempotency_token', $token)->first();

            if (! $existing instanceof Expense) {
                throw $e;
            }

            return $existing;
        }

        return $expense;
    }

    /**
     * @throws FinanceFailed
     * @throws AuthorizationException
     */
    public function void(Expense $expense, User $actingUser, string $reason, ?CarbonImmutable $now = null): Expense
    {
        $this->access->ensure($actingUser, Permission::ExpenseManage, $expense->branch_id, 'You may not void expenses.');

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw FinanceFailed::policy('Voiding an expense needs a reason.');
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var Expense $voided */
        $voided = DB::connection('tenant')->transaction(function () use ($expense, $actingUser, $reason, $at): Expense {
            /** @var Expense $locked */
            $locked = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === ExpenseStatus::Voided) {
                return $locked;
            }

            $shiftId = null;

            if ($locked->cashier_shift_id !== null) {
                /** @var CashierShift|null $shift */
                $shift = CashierShift::query()->whereKey($locked->cashier_shift_id)->lockForUpdate()->first();
                $shiftId = $shift instanceof CashierShift && $shift->status === ShiftStatus::Open ? $shift->getKey() : null;
            }

            $locked->forceFill([
                'status' => ExpenseStatus::Voided,
                'voided_at' => $at,
                'voided_by_id' => $actingUser->uuid,
                'voided_by_label' => $actingUser->name,
                'void_reason' => $reason,
            ])->save();

            $this->ledger->append(
                kind: EntryKind::ExpenseReversal,
                source: EntrySource::Expense,
                sourceUuid: $locked->uuid,
                branchId: $locked->branch_id,
                amountMinor: $locked->amount_minor,
                currency: $locked->currency,
                method: $locked->method,
                provider: null,
                cashierShiftId: $shiftId,
                label: 'Expense voided',
                occurredAt: $at,
            );

            $this->audit->record('finance.expense.voided', $actingUser, $locked, $locked->uuid,
                after: ['status' => ExpenseStatus::Voided->value],
                before: ['status' => ExpenseStatus::Posted->value, 'amount_minor' => $locked->amount_minor],
                reason: $reason,
                severity: AuditSeverity::Warning,
            );

            return $locked;
        });

        return $voided;
    }

    /**
     * @throws FinanceFailed
     */
    private function ownOpenShift(User $actingUser, int $branchId): CashierShift
    {
        /** @var CashierShift|null $shift */
        $shift = CashierShift::query()
            ->where('active_user_id', $actingUser->getKey())
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (! $shift instanceof CashierShift || $shift->status !== ShiftStatus::Open) {
            throw FinanceFailed::policy('To pay from the drawer, open your cashier shift at this branch first.');
        }

        return $shift;
    }

    private static function optional(?string $value, int $max): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * @throws FinanceFailed
     */
    private static function token(?string $token): ?string
    {
        $token = $token === null ? null : trim($token);

        if ($token === null || $token === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $token) !== 1) {
            throw FinanceFailed::policy('That request token is not valid.');
        }

        return $token;
    }
}
