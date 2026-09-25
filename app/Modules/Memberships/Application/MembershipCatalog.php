<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Entitlements\Entitlements;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Sales\Contracts\OfferingCatalog;
use App\Modules\Sales\Domain\Data\OfferingItem;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * Membership plans, sold through the till as an ordinary line
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §11).
 *
 * Selling a new membership needs the `memberships` entitlement. Sales prices
 * the line from `offer()` — never from the browser — and knows nothing else
 * about it.
 */
final class MembershipCatalog implements OfferingCatalog
{
    public const TYPE = 'membership';

    public function __construct(private readonly Entitlements $entitlements) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function available(): array
    {
        if (! $this->entitlements->enabled('memberships')) {
            return [];
        }

        $items = [];

        foreach (MembershipPlan::query()->active()->has('benefits')->orderBy('sort_order')->orderBy('id')->limit(100)->get() as $plan) {
            $items[] = new OfferingItem($plan->uuid, $plan->name, $plan->price_minor);
        }

        return $items;
    }

    public function offer(string $reference, Sale $sale): OfferingItem
    {
        $this->entitlements->ensure('memberships');

        /** @var MembershipPlan|null $plan */
        $plan = MembershipPlan::query()->active()->where('uuid', $reference)->withCount('benefits')->first();

        if (! $plan instanceof MembershipPlan || (int) $plan->getAttribute('benefits_count') < 1) {
            throw SaleFailed::policy('That membership is not available to sell.');
        }

        return new OfferingItem($plan->uuid, $plan->name, $plan->price_minor);
    }
}
