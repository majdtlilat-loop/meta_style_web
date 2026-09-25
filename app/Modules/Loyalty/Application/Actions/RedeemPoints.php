<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyAccounts;
use App\Modules\Loyalty\Application\LoyaltyAudit;
use App\Modules\Loyalty\Application\LoyaltyLedger;
use App\Modules\Loyalty\Application\LoyaltySync;
use App\Modules\Loyalty\Application\PointsExpiry;
use App\Modules\Loyalty\Application\PointsLots;
use App\Modules\Loyalty\Application\RedeemedPoints;
use App\Modules\Loyalty\Domain\Enums\PointsDirection;
use App\Modules\Loyalty\Domain\Enums\PointsKind;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Sales\Application\SaleBenefits;
use App\Modules\Sales\Domain\Data\BenefitGrant;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Redeems points at checkout, as a discount on a DRAFT — and withdraws it.
 *
 *     BEGIN
 *       lock the sale                         (SaleBenefits → SaleMutation)
 *       the sale has a customer, and no points redeemed on it yet
 *       lock the customer's loyalty account
 *       write off anything that aged out      (PointsExpiry)
 *       enough points, at least the minimum, no more than is left to pay
 *       write the `redeem` row
 *       the discount: points × point value, on the whole sale
 *       audit
 *     COMMIT
 *
 * The sale's lines keep their prices; the discount is a benefit adjustment
 * Sales re-prices exactly like any other. Once the invoice is published the
 * sale is no longer a draft and SaleBenefits refuses — so points are never
 * redeemed against an issued invoice. Withdrawing, discarding the draft and
 * voiding the sale all give the points back (`RedeemedPoints`)
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §7).
 *
 * Who: the till's own `sale.create` (via SaleBenefits) plus `loyalty.view`,
 * and the `loyalty` entitlement.
 */
final class RedeemPoints
{
    public const SOURCE = 'loyalty';

    public function __construct(
        private readonly LoyaltyAccess $access,
        private readonly LoyaltyAccounts $accounts,
        private readonly LoyaltyLedger $ledger,
        private readonly PointsExpiry $expiry,
        private readonly PointsLots $lots,
        private readonly LoyaltySync $sync,
        private readonly SaleBenefits $benefits,
        private readonly RedeemedPoints $redeemed,
        private readonly LoyaltyAudit $audit,
    ) {}

    /**
     * @throws LoyaltyFailed
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function apply(Sale $sale, User $actingUser, int $points, ?CarbonImmutable $now = null): SaleAdjustment
    {
        $this->access->ensure($actingUser, Permission::LoyaltyView, 'You may not use loyalty points.');

        $program = LoyaltyProgram::current();

        if (! $program instanceof LoyaltyProgram || ! $program->allowsRedemption()) {
            throw LoyaltyFailed::policy('This center does not let points be redeemed.');
        }

        if ($points < max(1, $program->min_redeem_points) || $points > LoyaltyLedger::MAX_POINTS) {
            throw LoyaltyFailed::policy('Redeem at least '.max(1, $program->min_redeem_points).' points.');
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        // Anything a lost after-commit callback left unearned is earned first,
        // from the payments themselves — before the balance is trusted (§1).
        if ($sale->customer_id !== null) {
            $this->sync->reconcileCustomer($sale->customer_id);
        }

        return $this->benefits->apply($sale, $actingUser, null, function (Sale $locked) use ($program, $points, $actingUser, $at): BenefitGrant {
            if ($locked->customer_id === null) {
                throw LoyaltyFailed::policy('Attach the customer to the sale before redeeming their points.');
            }

            if ($this->benefits->on($locked, self::SOURCE) !== []) {
                throw LoyaltyFailed::policy('Points are already redeemed on this sale. Withdraw them to change the amount.');
            }

            $account = $this->accounts->lockFor($locked->customer_id);

            // Aged-out points are written off first: an expired point can
            // never be redeemed.
            $this->expiry->apply($account, $at);

            if ($account->balance < $points) {
                throw LoyaltyFailed::policy('Only '.$account->balance.' points are available.', ['available' => $account->balance]);
            }

            $amount = $points * $program->point_value_minor;
            $payable = $locked->subtotal_minor - $locked->discount_total_minor;

            if ($amount > $payable) {
                throw LoyaltyFailed::policy('Those points are worth more than is left to pay on this sale.', ['payable_minor' => $payable]);
            }

            $redemption = (string) Str::uuid();
            $actor = Actor::staff($actingUser);

            // The oldest valid credits are used first; if this redemption is
            // ever returned, the points come back with the latest expiry of
            // those it used — never a longer life than they had.
            $restoreExpiry = $this->lots->restoreExpiry($this->lots->state($account, $at)['lots'], $points);

            $this->ledger->append(
                $account,
                PointsKind::Redeem,
                PointsDirection::Out,
                $points,
                PointsSource::Benefit,
                $redemption,
                $at,
                contextUuid: $locked->uuid,
                reason: 'Redeemed at checkout',
                actor: $actor,
                expiresAt: $restoreExpiry,
            );

            $this->audit->record('loyalty.points_redeemed', $actor, $account, $account->uuid,
                after: ['points' => $points, 'amount_minor' => $amount, 'balance' => $account->balance],
                meta: ['sale' => $locked->uuid, 'redemption' => $redemption],
                severity: AuditSeverity::Notice,
            );

            return new BenefitGrant(self::SOURCE, $redemption, $amount, 'Loyalty points: '.$points);
        });
    }

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function withdraw(Sale $sale, User $actingUser): void
    {
        // Giving points back is never blocked by a downgrade.
        $this->access->authorize($actingUser, Permission::LoyaltyView, 'You may not use loyalty points.');

        $existing = $this->benefits->on($sale, self::SOURCE)[0] ?? null;

        if (! $existing instanceof SaleAdjustment || $existing->source_reference === null) {
            throw LoyaltyFailed::policy('No points are redeemed on this sale.');
        }

        $this->benefits->withdraw($sale, $actingUser, self::SOURCE, $existing->source_reference,
            function (Sale $locked, SaleAdjustment $adjustment) use ($actingUser): void {
                $this->redeemed->giveBack((string) $adjustment->source_reference, 'Withdrawn from the sale', Actor::staff($actingUser));
            },
        );
    }
}
