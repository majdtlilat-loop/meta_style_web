<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Memberships\Domain\Enums\DiscountKind;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\CustomerMembershipBenefit;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Memberships\Domain\Models\MembershipPlanBenefit;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Carbon\CarbonImmutable;

/**
 * Allow-lists for memberships — staff and customer.
 *
 * Uses so far are computed from the history for every membership in ONE query,
 * never stored and never counted row by row. The customer sees names, what the
 * membership gives, what is left and when it runs — never a sale, an id, who
 * cancelled or why (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §19).
 */
final class MembershipsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function plan(MembershipPlan $plan): array
    {
        $locale = app()->getLocale();

        return [
            'uuid' => $plan->uuid,
            'name' => $plan->name->get($locale),
            'names' => $plan->name->all(),
            'price' => Money::fromMinor($plan->price_minor, Currency::default())->toArray($locale),
            'duration_days' => $plan->duration_days,
            'sort_order' => $plan->sort_order,
            'archived' => $plan->archived_at !== null,
            'benefits' => array_values($plan->benefits->map(fn (MembershipPlanBenefit $benefit): array => [
                'service' => $benefit->service?->uuid,
                'service_name' => $benefit->service?->name->get($locale),
                'discount_type' => $benefit->discount_type->value,
                'percent' => $benefit->basis_points === null ? null : SalePricing::percentLabel($benefit->basis_points),
                'amount' => $benefit->amount_minor === null ? null : Money::fromMinor($benefit->amount_minor, Currency::default())->toArray($locale),
                'uses_per_term' => $benefit->uses_per_term,
            ])->all()),
        ];
    }

    /**
     * @param  list<CustomerMembership>  $memberships
     * @return list<array<string, mixed>>
     */
    public function forStaff(array $memberships): array
    {
        $used = MembershipUsageLedger::usedFor(array_map(static fn (CustomerMembership $m): int => (int) $m->getKey(), $memberships));
        $zones = $this->timezones($memberships);
        $now = CarbonImmutable::now();

        return array_map(fn (CustomerMembership $membership): array => $this->common($membership, $used[(int) $membership->getKey()] ?? [], $now, true, $zones[$membership->branch_id] ?? 'UTC') + [
            'uuid' => $membership->uuid,
            'activated_at' => $membership->activated_at->toIso8601String(),
            'cancelled_at' => $membership->cancelled_at?->toIso8601String(),
            'cancelled_by' => $membership->cancelled_by_label,
            'cancel_reason' => $membership->cancel_reason,
        ], $memberships);
    }

    /**
     * The plans page's members list: who holds which membership and until
     * when — the customer's NAME and uuid, never their contact. The branch-local
     * last day comes from the branch it was sold at, as everywhere (§11).
     *
     * @param  list<CustomerMembership>  $memberships
     * @return list<array<string, mixed>>
     */
    public function members(array $memberships): array
    {
        $zones = $this->timezones($memberships);
        $now = CarbonImmutable::now();
        $locale = app()->getLocale();

        return array_map(function (CustomerMembership $membership) use ($zones, $now, $locale): array {
            $customer = $membership->relationLoaded('customer') ? $membership->customer : null;
            $timezone = $zones[$membership->branch_id] ?? 'UTC';

            return [
                'uuid' => $membership->uuid,
                'name' => $membership->name->get($locale),
                'customer' => $customer === null ? null : ['uuid' => $customer->uuid, 'name' => $customer->name],
                'state' => $membership->state($now),
                'first_day' => BranchClock::localDate(CarbonImmutable::instance($membership->starts_at), $timezone),
                'last_day' => BranchClock::localDate(CarbonImmutable::instance($membership->expires_at)->subSecond(), $timezone),
            ];
        }, $memberships);
    }

    /**
     * @param  list<CustomerMembership>  $memberships
     * @return list<array<string, mixed>>
     */
    public function forCustomer(array $memberships): array
    {
        $used = MembershipUsageLedger::usedFor(array_map(static fn (CustomerMembership $m): int => (int) $m->getKey(), $memberships));
        $zones = $this->timezones($memberships);
        $now = CarbonImmutable::now();

        return array_map(fn (CustomerMembership $membership): array => $this->common($membership, $used[(int) $membership->getKey()] ?? [], $now, false, $zones[$membership->branch_id] ?? 'UTC'), $memberships);
    }

    /**
     * @param  array<int, int>  $used
     * @return array<string, mixed>
     */
    private function common(CustomerMembership $membership, array $used, CarbonImmutable $now, bool $forStaff, string $timezone): array
    {
        $locale = app()->getLocale();

        return [
            'name' => $membership->name->get($locale),
            'state' => $membership->state($now),
            'starts_at' => $membership->starts_at->toIso8601String(),
            'expires_at' => $membership->expires_at->toIso8601String(),
            // The branch-local calendar days it covers: it ends at the START
            // of the day after `last_day`, so that is the last day it works.
            'first_day' => BranchClock::localDate(CarbonImmutable::instance($membership->starts_at), $timezone),
            'last_day' => BranchClock::localDate(CarbonImmutable::instance($membership->expires_at)->subSecond(), $timezone),
            'benefits' => array_values($membership->benefits->map(function (CustomerMembershipBenefit $benefit) use ($used, $locale, $forStaff, $membership): array {
                $usedSoFar = max(0, $used[(int) $benefit->getKey()] ?? 0);

                $row = [
                    'service_name' => $benefit->service_name?->get($locale),
                    'all_services' => $benefit->service_id === null,
                    'discount_type' => $benefit->discount_type->value,
                    'percent' => $benefit->discount_type === DiscountKind::Percent && $benefit->basis_points !== null
                        ? SalePricing::percentLabel($benefit->basis_points) : null,
                    'amount' => $benefit->discount_type === DiscountKind::Fixed && $benefit->amount_minor !== null
                        ? Money::fromMinor($benefit->amount_minor, Currency::tryFrom($membership->currency) ?? Currency::default())->toArray($locale) : null,
                    'uses_limit' => $benefit->uses_limit,
                    'uses_left' => $benefit->uses_limit === null ? null : max(0, $benefit->uses_limit - $usedSoFar),
                ];

                // Staff pick a benefit by its uuid at the till; the customer never needs one.
                return $forStaff ? ['uuid' => $benefit->uuid] + $row : $row;
            })->all()),
        ];
    }

    /**
     * Each membership's branch timezone — one query for all of them.
     *
     * @param  list<CustomerMembership>  $memberships
     * @return array<int, string>
     */
    private function timezones(array $memberships): array
    {
        $ids = array_values(array_unique(array_map(static fn (CustomerMembership $m): int => $m->branch_id, $memberships)));

        /** @var array<int, string> $zones */
        $zones = $ids === [] ? [] : Branch::query()->whereIn('id', $ids)->pluck('timezone', 'id')->all();

        return $zones;
    }
}
