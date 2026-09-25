<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyAccounts;
use App\Modules\Loyalty\Application\LoyaltyAudit;
use App\Modules\Loyalty\Application\LoyaltyLedger;
use App\Modules\Loyalty\Application\LoyaltySync;
use App\Modules\Loyalty\Application\PointsExpiry;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A manager's reasoned correction to a customer's points.
 *
 * Never an edit of the balance: an `adjustment` row, in or out, with who, when
 * and why — appended by the ledger under the account lock, audited in the same
 * transaction. Taking points out can never take the balance below zero
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §21).
 */
final class AdjustPoints
{
    public function __construct(
        private readonly LoyaltyAccess $access,
        private readonly LoyaltyAccounts $accounts,
        private readonly LoyaltyLedger $ledger,
        private readonly PointsExpiry $expiry,
        private readonly LoyaltySync $sync,
        private readonly LoyaltyAudit $audit,
    ) {}

    /**
     * @throws LoyaltyFailed
     * @throws AuthorizationException
     */
    public function __invoke(string $customerUuid, User $actingUser, PointsDirection $direction, int $points, string $reason, ?CarbonImmutable $now = null): LoyaltyTransaction
    {
        $this->access->ensure($actingUser, Permission::LoyaltyAdjust, __('manager_benefits.errors.may_not_adjust'));

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('uuid', $customerUuid)->first();

        if (! $customer instanceof Customer) {
            throw new NotFoundHttpException;
        }

        if ($points < 1 || $points > LoyaltyLedger::MAX_POINTS) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.adjust_min'));
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.adjust_reason'));
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        // What a lost after-commit callback left unearned is earned first, so
        // the balance adjusted is the one the payments really produced (§1).
        $this->sync->reconcileCustomer((int) $customer->getKey());

        /** @var LoyaltyTransaction $transaction */
        $transaction = DB::connection('tenant')->transaction(function () use ($customer, $actingUser, $direction, $points, $reason, $at): LoyaltyTransaction {
            $account = $this->accounts->lockFor((int) $customer->getKey());

            // Aged-out points are written off first, so an adjustment out never
            // takes points that are not really there.
            $this->expiry->apply($account, $at);
            $program = LoyaltyProgram::current();

            if ($direction === PointsDirection::Out && $account->balance < $points) {
                throw LoyaltyFailed::policy(__('manager_benefits.errors.adjust_available', ['n' => $account->balance]), ['available' => $account->balance]);
            }

            $actor = Actor::staff($actingUser);
            $before = $account->balance;

            $transaction = $this->ledger->append(
                $account,
                PointsKind::Adjustment,
                $direction,
                $points,
                PointsSource::Manual,
                (string) Str::uuid(),
                $at,
                reason: $reason,
                actor: $actor,
                // Points given by hand age like points earned today.
                expiresAt: $direction === PointsDirection::In ? $program?->creditExpiry($at) : null,
            );

            $this->audit->record('loyalty.points_adjusted', $actor, $account, $account->uuid,
                after: ['balance' => $account->balance],
                meta: ['direction' => $direction->value, 'points' => $points],
                before: ['balance' => $before],
                reason: $reason,
                severity: AuditSeverity::Warning,
            );

            return $transaction;
        });

        return $transaction;
    }
}
