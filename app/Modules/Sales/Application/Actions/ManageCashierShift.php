<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Domain\Enums\ShiftStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\CashierShift;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opens and closes a cashier's shift.
 *
 * ## The invariant: one open shift per user per branch
 *
 * Backed twice. Opening locks the USER row first, so two "open shift" taps by
 * the same person serialise instead of racing; and `unique(active_user_id,
 * branch_id)` refuses a second open row even if a future path forgets the lock.
 * Either way the second tap receives the shift that is already open — it is a
 * repeat, not an error (docs/18-SALES.md §§13, 49).
 *
 * A person may hold open shifts at two different branches at once: a manager
 * covering two tills is a real day, and the invariant is about not opening the
 * SAME till twice.
 *
 * ## Closing is not reconciling
 *
 * It records when the session ended and who ended it. The opening cash is
 * recorded here, because it is a fact about the drawer when the session starts;
 * counting the drawer at close, the expected amount and the variance are
 * Finance's `CloseShiftWithCount`, which wraps this close in its own
 * transaction (docs/20-FINANCE.md §§31–33).
 */
final class ManageCashierShift
{
    /**
     * A sanity bound on a counted drawer, in minor units — the same ceiling one
     * sale may reach, so a mistyped extra zero is refused rather than stored.
     */
    public const MAX_DRAWER_MINOR = 3_000_000_000;

    public function __construct(
        private readonly SalesAccess $access,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function open(string $branchUuid, User $actingUser, ?string $note = null, ?CarbonImmutable $now = null, ?int $openingCashMinor = null): CashierShift
    {
        /** @var Branch|null $branch */
        $branch = Branch::query()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch || ! $branch->is_active || $branch->archived_at !== null) {
            throw SaleFailed::policy('That branch is not available for sales.');
        }

        $this->access->ensure($actingUser, Permission::CashierShiftManage, $branch->id, 'You may not open a cashier shift.');

        if ($openingCashMinor !== null && ($openingCashMinor < 0 || $openingCashMinor > self::MAX_DRAWER_MINOR)) {
            throw SaleFailed::policy('The opening cash must be zero or more, and within a drawer range.');
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        try {
            /** @var array{0: CashierShift, 1: bool} $outcome */
            $outcome = DB::connection('tenant')->transaction(function () use ($branch, $actingUser, $note, $at, $openingCashMinor): array {
                // Serialises this person's shift operations. The users row
                // always exists, so there is no gap-lock subtlety to reason
                // about, unlike locking a shift row that may not exist yet.
                DB::connection('tenant')->table('users')
                    ->where('id', $actingUser->getKey())
                    ->lockForUpdate()
                    ->first();

                $existing = $this->currentFor($actingUser, $branch->id);

                if ($existing instanceof CashierShift) {
                    return [$existing, false];
                }

                /** @var CashierShift $shift */
                $shift = CashierShift::query()->create([
                    'branch_id' => $branch->id,
                    'user_id' => $actingUser->getKey(),
                    'active_user_id' => $actingUser->getKey(),
                    'status' => ShiftStatus::Open,
                    'opened_at' => $at,
                    'opening_cash_minor' => $openingCashMinor,
                    'opening_note' => $this->note($note),
                ]);

                return [$shift, true];
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = $this->currentFor($actingUser, $branch->id);

            if (! $existing instanceof CashierShift) {
                throw $e;
            }

            return $existing;
        }

        [$shift, $opened] = $outcome;

        if ($opened) {
            $this->record('cashier_shift.opened', $actingUser, $shift);
        }

        return $shift;
    }

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function close(CashierShift $shift, User $actingUser, ?string $note = null, ?CarbonImmutable $now = null): CashierShift
    {
        // Your own till needs `cashier_shift.manage`; somebody else's needs a
        // supervisor. A cashier must not be able to end a colleague's session.
        $permission = $shift->user_id === $actingUser->getKey()
            ? Permission::CashierShiftManage
            : Permission::CashierShiftSupervise;

        $this->access->ensure($actingUser, $permission, $shift->branch_id, 'You may not close that cashier shift.');

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var array{0: CashierShift, 1: bool} $outcome */
        $outcome = DB::connection('tenant')->transaction(function () use ($shift, $actingUser, $note, $at): array {
            /** @var CashierShift|null $locked */
            $locked = CashierShift::query()->whereKey($shift->getKey())->lockForUpdate()->first();

            if (! $locked instanceof CashierShift) {
                throw SaleFailed::policy('That shift no longer exists.');
            }

            if (! $locked->isOpen()) {
                return [$locked, false];
            }

            $locked->forceFill([
                'status' => ShiftStatus::Closed,
                'closed_at' => $at,
                'closing_note' => $this->note($note),
                'closed_by_id' => $actingUser->uuid,
                'closed_by_label' => $actingUser->name,
                'active_user_id' => null,
            ])->save();

            return [$locked, true];
        });

        [$closed, $changed] = $outcome;

        if ($changed) {
            $this->record('cashier_shift.closed', $actingUser, $closed, [
                'on_behalf' => $closed->user_id !== $actingUser->getKey(),
            ]);
        }

        return $closed;
    }

    public function currentFor(User $user, int $branchId): ?CashierShift
    {
        /** @var CashierShift|null $shift */
        $shift = CashierShift::query()
            ->where('active_user_id', $user->getKey())
            ->where('branch_id', $branchId)
            ->first();

        return $shift;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function record(string $action, User $actor, CashierShift $shift, array $meta = []): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Finance,
            actor: Actor::staff($actor),
            targetType: CashierShift::class,
            targetId: $shift->uuid,
            after: ['status' => $shift->status->value],
            meta: $meta,
        ));
    }

    private function note(?string $note): ?string
    {
        $note = $note === null ? null : trim($note);

        return $note === null || $note === '' ? null : mb_substr($note, 0, 190);
    }
}
