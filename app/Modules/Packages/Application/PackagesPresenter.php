<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\CustomerPackageItem;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use App\Modules\Packages\Domain\Models\PackageDefinitionItem;
use Carbon\CarbonImmutable;

/**
 * Allow-lists for packages — staff and customer.
 *
 * Sessions left are computed from the history for every package in ONE query,
 * never stored and never counted row by row. The customer sees names, what is
 * left and when it ends — never a sale, an id, who cancelled or why
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §19).
 */
final class PackagesPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function definition(PackageDefinition $definition): array
    {
        $locale = app()->getLocale();

        return [
            'uuid' => $definition->uuid,
            'name' => $definition->name->get($locale),
            'names' => $definition->name->all(),
            'price' => Money::fromMinor($definition->price_minor, Currency::default())->toArray($locale),
            'validity_days' => $definition->validity_days,
            'sort_order' => $definition->sort_order,
            'archived' => $definition->archived_at !== null,
            'items' => array_values($definition->items->map(fn (PackageDefinitionItem $item): array => [
                'service' => $item->service?->uuid,
                'service_name' => $item->service?->name->get($locale),
                'variation' => $item->variation?->uuid,
                'variation_name' => $item->variation?->name->get($locale),
                'quantity' => $item->quantity,
            ])->all()),
        ];
    }

    /**
     * @param  list<CustomerPackage>  $packages
     * @return list<array<string, mixed>>
     */
    public function forStaff(array $packages): array
    {
        $left = PackageLedger::leftFor(array_map(static fn (CustomerPackage $p): int => (int) $p->getKey(), $packages));
        $zones = $this->timezones($packages);
        $now = CarbonImmutable::now();

        return array_map(fn (CustomerPackage $package): array => $this->common($package, $left[(int) $package->getKey()] ?? [], $now, $zones[$package->branch_id] ?? 'UTC') + [
            'uuid' => $package->uuid,
            'activated_at' => $package->activated_at->toIso8601String(),
            'cancelled_at' => $package->cancelled_at?->toIso8601String(),
            'cancelled_by' => $package->cancelled_by_label,
            'cancel_reason' => $package->cancel_reason,
        ], $packages);
    }

    /**
     * The packages page's holders list: who holds which package, what is left
     * and the last branch-local day — the customer's NAME and uuid, never their
     * contact. Sessions left come from the history in ONE query (§14).
     *
     * @param  list<CustomerPackage>  $packages
     * @return list<array<string, mixed>>
     */
    public function holders(array $packages): array
    {
        $left = PackageLedger::leftFor(array_map(static fn (CustomerPackage $p): int => (int) $p->getKey(), $packages));
        $zones = $this->timezones($packages);
        $now = CarbonImmutable::now();

        return array_map(function (CustomerPackage $package) use ($left, $zones, $now): array {
            $customer = $package->relationLoaded('customer') ? $package->customer : null;

            return $this->common($package, $left[(int) $package->getKey()] ?? [], $now, $zones[$package->branch_id] ?? 'UTC') + [
                'uuid' => $package->uuid,
                'customer' => $customer === null ? null : ['uuid' => $customer->uuid, 'name' => $customer->name],
            ];
        }, $packages);
    }

    /**
     * @param  list<CustomerPackage>  $packages
     * @return list<array<string, mixed>>
     */
    public function forCustomer(array $packages): array
    {
        $left = PackageLedger::leftFor(array_map(static fn (CustomerPackage $p): int => (int) $p->getKey(), $packages));
        $zones = $this->timezones($packages);
        $now = CarbonImmutable::now();

        return array_map(fn (CustomerPackage $package): array => $this->common($package, $left[(int) $package->getKey()] ?? [], $now, $zones[$package->branch_id] ?? 'UTC'), $packages);
    }

    /**
     * @param  array<int, int>  $left
     * @return array<string, mixed>
     */
    private function common(CustomerPackage $package, array $left, CarbonImmutable $now, string $timezone): array
    {
        $locale = app()->getLocale();

        return [
            'name' => $package->name->get($locale),
            'state' => $package->state($now),
            'expires_at' => $package->expires_at->toIso8601String(),
            // The last branch-local day it can be used: it ends at the start of the next.
            'last_day' => BranchClock::localDate(CarbonImmutable::instance($package->expires_at)->subSecond(), $timezone),
            'items' => array_values($package->items->map(fn (CustomerPackageItem $item): array => [
                'name' => $item->name->get($locale),
                'variation' => $item->variation_name?->get($locale),
                'allocated' => $item->quantity,
                'left' => max(0, $left[(int) $item->getKey()] ?? 0),
            ])->all()),
        ];
    }

    /**
     * Each package's branch timezone — one query for all of them.
     *
     * @param  list<CustomerPackage>  $packages
     * @return array<int, string>
     */
    private function timezones(array $packages): array
    {
        $ids = array_values(array_unique(array_map(static fn (CustomerPackage $p): int => $p->branch_id, $packages)));

        /** @var array<int, string> $zones */
        $zones = $ids === [] ? [] : Branch::query()->whereIn('id', $ids)->pluck('timezone', 'id')->all();

        return $zones;
    }
}
