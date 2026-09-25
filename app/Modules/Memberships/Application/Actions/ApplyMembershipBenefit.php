<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Memberships\Application\ActivateMemberships;
use App\Modules\Memberships\Application\MembershipsAccess;
use App\Modules\Memberships\Application\MembershipsAudit;
use App\Modules\Memberships\Application\MembershipUsageLedger;
use App\Modules\Memberships\Application\UsedBenefits;
use App\Modules\Memberships\Domain\Enums\DiscountKind;
use App\Modules\Memberships\Domain\Enums\UsageKind;
use App\Modules\Memberships\Domain\Enums\UsageSource;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Sales\Application\SaleBenefits;
use App\Modules\Sales\Domain\Data\BenefitGrant;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Gives a member their discount on a service line at checkout — and takes it
 * back.
 *
 *     sync the customer's memberships        (a lost activation, activated)
 *     BEGIN
 *       lock the sale                          (SaleBenefits)
 *       the line is a SERVICE on this draft, with no other benefit
 *       lock the customer's membership         ← the second lock, always
 *       it is theirs, active, inside its term
 *       the benefit covers this service
 *       uses left this term, when limited — proven from the history
 *       write the `use` (one per unit of the line)
 *       the discount on that line only:
 *         percent  round_half_up(service price × quantity × bp ÷ 10 000)
 *         fixed    min(amount × quantity, service price × quantity)
 *     COMMIT
 *
 * The discount is taken from the SERVICE's own price; add-ons stay charged, as
 * with packages. Using a membership the customer ALREADY PAID FOR needs
 * `membership.view` and the till's own `sale.create` — not the `memberships`
 * entitlement, which governs selling new ones
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§12, 22).
 */
final class ApplyMembershipBenefit
{
    public const SOURCE = 'membership';

    public function __construct(
        private readonly MembershipsAccess $access,
        private readonly MembershipUsageLedger $ledger,
        private readonly ActivateMemberships $activation,
        private readonly SaleBenefits $benefits,
        private readonly UsedBenefits $used,
        private readonly MembershipsAudit $audit,
    ) {}

    /**
     * @throws MembershipsFailed
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function apply(Sale $sale, User $actingUser, string $lineUuid, string $benefitUuid, ?CarbonImmutable $now = null): SaleAdjustment
    {
        $this->access->authorize($actingUser, Permission::MembershipView, 'You may not use customers\' memberships.');

        $at = ($now ?? CarbonImmutable::now())->utc();

        // A membership the customer paid for but a lost callback never
        // activated is activated first, from the sale and its payments (§1).
        if ($sale->customer_id !== null) {
            $this->activation->reconcileCustomer($sale->customer_id);
        }

        return $this->benefits->apply($sale, $actingUser, $lineUuid, function (Sale $locked, ?SaleItem $line) use ($benefitUuid, $actingUser, $at): BenefitGrant {
            if (! $line instanceof SaleItem || $line->kind !== SaleItemKind::Service || $line->service_id === null) {
                throw MembershipsFailed::policy('A membership benefit applies to a service line.');
            }

            if ($locked->customer_id === null) {
                throw MembershipsFailed::policy('Attach the customer to the sale before using their membership.');
            }

            /** @var CustomerMembershipBenefit|null $benefit */
            $benefit = CustomerMembershipBenefit::query()->where('uuid', $benefitUuid)->first();

            /** @var CustomerMembership|null $membership */
            $membership = $benefit === null
                ? null
                : CustomerMembership::query()->whereKey($benefit->customer_membership_id)->lockForUpdate()->first();

            if (! $benefit instanceof CustomerMembershipBenefit || ! $membership instanceof CustomerMembership
                || $membership->customer_id !== $locked->customer_id) {
                throw MembershipsFailed::policy('That membership is not this customer\'s.');
            }

            if (! $membership->isUsable($at)) {
                throw MembershipsFailed::invalidTransition('That membership is '.$membership->state($at).'.');
            }

            if (! $benefit->covers($line->service_id)) {
                throw MembershipsFailed::policy('That membership benefit does not cover this service.');
            }

            $quantity = $line->quantity;

            if ($benefit->uses_limit !== null) {
                $left = $benefit->uses_limit - ($this->ledger->used($membership)[(int) $benefit->getKey()] ?? 0);

                if ($left < $quantity) {
                    throw MembershipsFailed::policy('Only '.max(0, $left).' uses of it are left this term.', ['left' => max(0, $left)]);
                }
            }

            $servicePrice = $line->unit_price_minor * $quantity;

            $amount = $benefit->discount_type === DiscountKind::Percent
                ? SalePricing::percentOf($servicePrice, (int) $benefit->basis_points)
                : min((int) $benefit->amount_minor * $quantity, $servicePrice);

            if ($amount < 1) {
                throw MembershipsFailed::policy('That benefit takes nothing off this line.');
            }

            $use = (string) Str::uuid();
            $actor = Actor::staff($actingUser);

            $this->ledger->append($membership, $benefit, UsageKind::Use, $quantity,
                UsageSource::Benefit, $use, $at,
                saleUuid: $locked->uuid, reason: 'Used at checkout', actor: $actor);

            $this->audit->record('membership.benefit_used', $actor, $membership, $membership->uuid,
                after: ['quantity' => $quantity, 'amount_minor' => $amount],
                meta: ['sale' => $locked->uuid, 'line' => $line->uuid, 'benefit' => $benefit->uuid, 'use' => $use],
                severity: AuditSeverity::Notice,
            );

            return new BenefitGrant(self::SOURCE, $use, $amount, 'Membership: '.$membership->name->get(app()->getLocale()));
        });
    }

    /**
     * @throws SaleFailed
     * @throws AuthorizationException
     */
    public function withdraw(Sale $sale, User $actingUser, string $lineUuid): void
    {
        $this->access->authorize($actingUser, Permission::MembershipView, 'You may not use customers\' memberships.');

        $adjustment = SaleAdjustment::query()
            ->where('sale_id', $sale->getKey())
            ->where('source_type', self::SOURCE)
            ->whereIn('sale_item_id', SaleItem::query()->select('id')->where('sale_id', $sale->getKey())->where('uuid', $lineUuid))
            ->first();

        if (! $adjustment instanceof SaleAdjustment || $adjustment->source_reference === null) {
            throw MembershipsFailed::policy('No membership benefit is applied to that line.');
        }

        $this->benefits->withdraw($sale, $actingUser, self::SOURCE, $adjustment->source_reference,
            function (Sale $locked, SaleAdjustment $benefit) use ($actingUser): void {
                $this->used->giveBack((string) $benefit->source_reference, 'Withdrawn from the sale', Actor::staff($actingUser));
            },
        );
    }
}
