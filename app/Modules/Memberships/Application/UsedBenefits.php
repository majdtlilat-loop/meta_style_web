<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Modules\Memberships\Application\Actions\ApplyMembershipBenefit;
use App\Modules\Memberships\Domain\Enums\CustomerMembershipStatus;
use App\Modules\Memberships\Domain\Enums\UsageKind;
use App\Modules\Memberships\Domain\Enums\UsageSource;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Memberships\Domain\Models\MembershipBenefitUsage;
use App\Modules\Sales\Domain\Events\SaleBenefitReleased;
use App\Modules\Sales\Domain\Events\SaleDraftDiscarded;
use App\Modules\Sales\Domain\Events\SaleVoided;
use App\Modules\Sales\Domain\Models\Sale;
use Carbon\CarbonImmutable;

/**
 * Gives membership uses back, and ends memberships a voided sale sold.
 *
 *   a benefit withdrawn, its draft discarded, its sale voided
 *        → a `reversal` of exactly that use, once
 *   the sale that SOLD a membership is voided
 *        → that membership is cancelled; its history stays
 *
 * SYNCHRONOUS, inside the sale's own transaction, after its lock: a void or a
 * discard moves no money, and a sale voided while its member discount stays
 * used would be a lie. If this cannot be made consistent the void is refused.
 * Never blocked by a downgrade (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§8, 22).
 */
final class UsedBenefits
{
    public function __construct(
        private readonly MembershipUsageLedger $ledger,
        private readonly MembershipsAudit $audit,
    ) {}

    public function giveBack(string $usageUuid, string $reason, Actor $actor): void
    {
        /** @var MembershipBenefitUsage|null $use */
        $use = MembershipBenefitUsage::query()
            ->where('source_type', UsageSource::Benefit->value)
            ->where('source_uuid', $usageUuid)
            ->where('kind', UsageKind::Use->value)
            ->first();

        if (! $use instanceof MembershipBenefitUsage) {
            return;
        }

        /** @var CustomerMembership $membership */
        $membership = CustomerMembership::query()->whereKey($use->customer_membership_id)->lockForUpdate()->firstOrFail();

        /** @var CustomerMembershipBenefit $benefit */
        $benefit = CustomerMembershipBenefit::query()->findOrFail($use->customer_membership_benefit_id);

        $returned = MembershipBenefitUsage::query()
            ->where('source_type', UsageSource::Benefit->value)
            ->where('source_uuid', $usageUuid)
            ->where('kind', UsageKind::Reversal->value)
            ->exists();

        if ($returned) {
            return;
        }

        $this->ledger->append($membership, $benefit, UsageKind::Reversal, $use->quantity,
            UsageSource::Benefit, $usageUuid, CarbonImmutable::now()->utc(),
            saleUuid: $use->sale_uuid, reason: $reason, actor: $actor);

        $this->audit->record('membership.use_returned', $actor, $membership, $membership->uuid,
            after: ['quantity' => $use->quantity],
            meta: ['use' => $usageUuid, 'sale' => $use->sale_uuid],
            reason: $reason,
        );
    }

    /**
     * Cancels a LOCKED membership: status, who and why. Idempotent. Its usage
     * history stays; a cancelled membership simply stops being usable.
     */
    public function cancel(CustomerMembership $locked, string $reason, Actor $actor, CarbonImmutable $at): void
    {
        if ($locked->status === CustomerMembershipStatus::Cancelled) {
            return;
        }

        $before = $locked->state($at);

        $locked->forceFill([
            'status' => CustomerMembershipStatus::Cancelled,
            'cancelled_at' => $at,
            'cancelled_by_id' => $actor->id,
            'cancelled_by_label' => $actor->label,
            'cancel_reason' => mb_substr($reason, 0, 190),
        ])->save();

        $this->audit->record('membership.cancelled', $actor, $locked, $locked->uuid,
            after: ['status' => CustomerMembershipStatus::Cancelled->value],
            before: ['state' => $before],
            reason: $reason,
            severity: AuditSeverity::Warning,
        );
    }

    public function handleSaleVoided(SaleVoided $event): void
    {
        /** @var Sale|null $sale */
        $sale = Sale::query()->find($event->saleId);

        if (! $sale instanceof Sale) {
            return;
        }

        $actor = Actor::system('memberships');

        $this->giveBackForSale($sale->uuid, 'Sale voided', $actor);

        // What the voided sale SOLD ends with it.
        foreach (CustomerMembership::query()->where('sale_id', $sale->getKey())->pluck('id') as $membershipId) {
            /** @var CustomerMembership $membership */
            $membership = CustomerMembership::query()->whereKey($membershipId)->lockForUpdate()->firstOrFail();

            $this->cancel($membership, 'The sale that sold it was voided', $actor, CarbonImmutable::now()->utc());
        }
    }

    public function handleSaleDraftDiscarded(SaleDraftDiscarded $event): void
    {
        $this->giveBackForSale($event->saleUuid, 'Draft discarded', Actor::system('memberships'));
    }

    /**
     * A hold on a draft nobody came back to expired: the use is the customer's
     * again (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §7).
     */
    public function handleBenefitReleased(SaleBenefitReleased $event): void
    {
        if ($event->sourceType === ApplyMembershipBenefit::SOURCE) {
            $this->giveBack($event->sourceReference, 'The draft holding it was abandoned', Actor::system('memberships'));
        }
    }

    private function giveBackForSale(string $saleUuid, string $reason, Actor $actor): void
    {
        $uses = MembershipBenefitUsage::query()
            ->where('sale_uuid', $saleUuid)
            ->where('source_type', UsageSource::Benefit->value)
            ->where('kind', UsageKind::Use->value)
            ->pluck('source_uuid')
            ->all();

        foreach ($uses as $usageUuid) {
            $this->giveBack((string) $usageUuid, $reason, $actor);
        }
    }
}
