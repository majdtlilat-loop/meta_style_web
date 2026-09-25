<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Audit\Actor;
use App\Modules\Memberships\Domain\Enums\UsageKind;
use App\Modules\Memberships\Domain\Enums\UsageSource;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Memberships\Domain\Models\MembershipBenefitUsage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * The only writer of `membership_benefit_usages`, and the only place "uses so
 * far" is computed.
 *
 *     used(benefit) = Σ use − Σ reversal
 *
 * Proven from the history every time; there is no counter to drift. Every
 * write runs on the LOCKED customer membership, in the caller's transaction,
 * and is idempotent per (source, source uuid, kind)
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §12).
 */
final class MembershipUsageLedger
{
    public function append(
        CustomerMembership $locked,
        CustomerMembershipBenefit $benefit,
        UsageKind $kind,
        int $quantity,
        UsageSource $source,
        string $sourceUuid,
        CarbonInterface $occurredAt,
        ?string $saleUuid = null,
        ?string $reason = null,
        ?Actor $actor = null,
    ): MembershipBenefitUsage {
        if (DB::connection('tenant')->transactionLevel() < 1) {
            throw new RuntimeException('MembershipUsageLedger::append() must run inside the transaction that used the benefit.');
        }

        if ($quantity < 1 || $quantity > 65_535 || $benefit->customer_membership_id !== (int) $locked->getKey()) {
            throw new LogicException('A benefit usage moves a positive quantity of one of its own benefits.');
        }

        /** @var MembershipBenefitUsage|null $existing */
        $existing = MembershipBenefitUsage::query()
            ->where('source_type', $source->value)
            ->where('source_uuid', $sourceUuid)
            ->where('kind', $kind->value)
            ->first();

        if ($existing instanceof MembershipBenefitUsage) {
            return $existing;
        }

        /** @var MembershipBenefitUsage $usage */
        $usage = MembershipBenefitUsage::query()->create([
            'customer_membership_id' => $locked->getKey(),
            'customer_membership_benefit_id' => $benefit->getKey(),
            'kind' => $kind,
            'quantity' => $quantity,
            'source_type' => $source,
            'source_uuid' => $sourceUuid,
            'sale_uuid' => $saleUuid,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 190),
            'actor_id' => $actor?->id,
            'actor_label' => $actor?->label,
            'occurred_at' => $occurredAt,
            'created_at' => now()->utc(),
        ]);

        return $usage;
    }

    /**
     * Uses so far, per benefit id — one grouped query.
     *
     * @return array<int, int>
     */
    public function used(CustomerMembership $membership): array
    {
        return self::usedFor([(int) $membership->getKey()])[(int) $membership->getKey()] ?? [];
    }

    /**
     * Uses so far for several memberships at once, per membership then per
     * benefit — one grouped query for any number of memberships.
     *
     * @param  list<int>  $membershipIds
     * @return array<int, array<int, int>>
     */
    public static function usedFor(array $membershipIds): array
    {
        if ($membershipIds === []) {
            return [];
        }

        $rows = MembershipBenefitUsage::query()
            ->whereIn('customer_membership_id', $membershipIds)
            ->groupBy('customer_membership_id', 'customer_membership_benefit_id', 'kind')
            ->selectRaw('customer_membership_id, customer_membership_benefit_id, kind, SUM(quantity) AS total')
            ->toBase()
            ->get();

        $used = [];

        foreach ($rows as $row) {
            $membership = (int) $row->customer_membership_id;
            $benefit = (int) $row->customer_membership_benefit_id;
            $kind = UsageKind::from((string) $row->kind);

            $used[$membership][$benefit] = ($used[$membership][$benefit] ?? 0) + $kind->sign() * (int) $row->total;
        }

        return $used;
    }
}
