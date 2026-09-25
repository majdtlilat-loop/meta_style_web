<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Models\LoyaltyRuleVersion;
use App\Modules\Loyalty\Domain\Models\LoyaltyTier;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use Carbon\CarbonImmutable;

/**
 * Allow-lists for loyalty — one for staff, one for the customer.
 *
 * The customer sees their points, their tier and what happened, in plain
 * kinds. Never who adjusted them, why, which payment or refund, or any id
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §19).
 */
final class LoyaltyPresenter
{
    public const RECENT = 20;

    public function __construct(
        private readonly PointsExpiry $expiry,
        private readonly TierResolver $tiers,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function program(?LoyaltyProgram $program): ?array
    {
        if (! $program instanceof LoyaltyProgram) {
            return null;
        }

        return [
            'spend_points' => $program->spend_points,
            'spend_unit_minor' => $program->spend_unit_minor,
            'min_spend_minor' => $program->min_spend_minor,
            'visit_points' => $program->visit_points,
            'point_value_minor' => $program->point_value_minor,
            'min_redeem_points' => $program->min_redeem_points,
            'expiry_days' => $program->expiry_days,
            'updated_by' => $program->updated_by_label,
            'updated_at' => $program->updated_at?->toIso8601String(),
            // What is switched on, read from the program's own rules.
            'earns_on_spend' => $program->earnsOnSpend(),
            'earns_on_visits' => $program->earnsOnVisits(),
            'allows_redemption' => $program->allowsRedemption(),
            'spend_unit' => $this->money($program->spend_unit_minor),
            'min_spend' => $this->money($program->min_spend_minor),
            'point_value' => $this->money($program->point_value_minor),
        ];
    }

    /**
     * One version of the earning rules, for the rules history.
     *
     * @return array<string, mixed>
     */
    public function ruleVersion(LoyaltyRuleVersion $version): array
    {
        return [
            'uuid' => $version->uuid,
            'effective_from' => $version->effective_from->toIso8601String(),
            'spend_points' => $version->spend_points,
            'spend_unit' => $this->money($version->spend_unit_minor),
            'min_spend' => $this->money($version->min_spend_minor),
            'visit_points' => $version->visit_points,
            'expiry_days' => $version->expiry_days,
            'changed_by' => $version->changed_by_label,
        ];
    }

    /**
     * One row of the member list: the customer's NAME and uuid (never their
     * contact), their points and the tier those points reach.
     *
     * @return array<string, mixed>
     */
    public function member(LoyaltyAccount $account, ?LoyaltyProgram $program): array
    {
        $customer = $account->relationLoaded('customer') ? $account->customer : null;
        $tier = $this->tiers->tierFor($account->lifetime_points);

        return [
            'customer' => $customer instanceof Customer ? ['uuid' => $customer->uuid, 'name' => $customer->name] : null,
            'balance' => $account->balance,
            'lifetime_points' => $account->lifetime_points,
            'unrecovered_points' => $account->unrecovered_points,
            'tier' => $tier?->name->get(app()->getLocale()),
            'value' => $this->pointsValue($account->balance, $program),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function tier(LoyaltyTier $tier): array
    {
        $locale = app()->getLocale();

        return [
            'uuid' => $tier->uuid,
            'name' => $tier->name->get($locale),
            'names' => $tier->name->all(),
            'benefit_note' => $tier->benefit_note?->get($locale),
            'threshold_points' => $tier->threshold_points,
            'sort_order' => $tier->sort_order,
            'archived' => $tier->archived_at !== null,
        ];
    }

    /**
     * Staff view of one customer's points.
     *
     * @return array<string, mixed>
     */
    public function forStaff(?LoyaltyAccount $account, ?LoyaltyProgram $program): array
    {
        $summary = $this->summary($account, $program);

        // What the usable points are worth at the till today — the program's
        // point value, times the points. Display only; staff only.
        $summary['available_value'] = $this->pointsValue((int) $summary['available_points'], $program);
        $summary['lifetime_points'] = $account->lifetime_points ?? 0;
        // Refund reversals the balance could not cover yet; the next earnings
        // settle them. Staff-only: the customer's balance is simply not negative.
        $summary['unrecovered_points'] = $account->unrecovered_points ?? 0;
        $summary['recent'] = array_map(fn (LoyaltyTransaction $row): array => [
            'uuid' => $row->uuid,
            'kind' => $row->kind->value,
            'direction' => $row->direction->value,
            'points' => $row->points,
            'unrecovered_points' => $row->unrecovered_points,
            'source' => $row->source_type->value,
            'reason' => $row->reason,
            'by' => $row->actor_label,
            'occurred_at' => $row->occurred_at->toIso8601String(),
        ], $this->recent($account));

        return $summary;
    }

    /**
     * The customer's own view: points, tier, what happened. Nothing internal.
     *
     * @return array<string, mixed>
     */
    public function forCustomer(?LoyaltyAccount $account, ?LoyaltyProgram $program): array
    {
        $summary = $this->summary($account, $program);

        $summary['recent'] = array_map(fn (LoyaltyTransaction $row): array => [
            'kind' => $row->kind->value,
            'direction' => $row->direction->value,
            'points' => $row->points,
            'date' => $row->occurred_at->toDateString(),
        ], array_values(array_filter(
            $this->recent($account),
            // A reversal the customer could not cover moved no points; there
            // is nothing for them to see in it.
            static fn (LoyaltyTransaction $row): bool => $row->points > 0,
        )));

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(?LoyaltyAccount $account, ?LoyaltyProgram $program): array
    {
        $balance = $account->balance ?? 0;
        // From each credit's own expiry snapshot — never the program's current
        // setting. Shown without writing; the next movement records it.
        $expiring = $account instanceof LoyaltyAccount ? $this->expiry->due($account, CarbonImmutable::now()) : 0;

        $tier = $this->tiers->tierFor($account->lifetime_points ?? 0);
        $locale = app()->getLocale();

        return [
            // What can be used now: aged-out points are shown as gone even
            // before the next movement writes them off.
            'available_points' => $balance - $expiring,
            'expired_unwritten' => $expiring,
            'tier' => $tier === null ? null : [
                'name' => $tier->name->get($locale),
                'benefit_note' => $tier->benefit_note?->get($locale),
            ],
            'point_value_minor' => $program instanceof LoyaltyProgram ? $program->point_value_minor : 0,
        ];
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}|null
     */
    private function pointsValue(int $points, ?LoyaltyProgram $program): ?array
    {
        if (! $program instanceof LoyaltyProgram || ! $program->allowsRedemption() || $points < 1) {
            return null;
        }

        return Money::fromMinor($program->point_value_minor, Currency::default())->times($points)->toArray(app()->getLocale());
    }

    /**
     * @return array{amount: int, currency: string, formatted: string}
     */
    private function money(int $minor): array
    {
        return Money::fromMinor($minor, Currency::default())->toArray(app()->getLocale());
    }

    /**
     * @return list<LoyaltyTransaction>
     */
    private function recent(?LoyaltyAccount $account): array
    {
        if (! $account instanceof LoyaltyAccount) {
            return [];
        }

        /** @var list<LoyaltyTransaction> $rows */
        $rows = LoyaltyTransaction::query()
            ->where('loyalty_account_id', $account->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get()
            ->all();

        return $rows;
    }
}
