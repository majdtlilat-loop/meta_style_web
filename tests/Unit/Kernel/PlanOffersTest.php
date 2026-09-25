<?php

declare(strict_types=1);

use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\Entitlements\EntitlementType;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlanEntitlement;
use App\Kernel\SaaS\PlanOffers;
use App\Kernel\Usage\UsageCatalog;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Collection;

/*
|--------------------------------------------------------------------------
| PlanOffers — pure parts
|--------------------------------------------------------------------------
|
| "Available from X" must be the same answer on every request for the same
| catalog: the commercial order is total (sort order, then monthly-equivalent
| price, then id), and a plan's EFFECTIVE features apply the dependency
| closure, so a plan that lists a feature without what it needs does not sell
| it. No database here: plans are built in memory.
|
*/

function planOffersCatalog(): EntitlementCatalog
{
    return new EntitlementCatalog(new Repository(['entitlements' => ['entitlements' => [
        'booking' => ['type' => EntitlementType::Boolean, 'category' => 'core'],
        'customer_accounts' => ['type' => EntitlementType::Boolean, 'category' => 'core'],
        'pos' => ['type' => EntitlementType::Boolean, 'category' => 'commerce'],
        'finance' => ['type' => EntitlementType::Boolean, 'category' => 'commerce', 'requires' => ['pos']],
        'payments' => ['type' => EntitlementType::Boolean, 'category' => 'commerce', 'requires' => ['pos']],
        'reports_standard' => ['type' => EntitlementType::Boolean, 'category' => 'insight'],
        'reports_advanced' => ['type' => EntitlementType::Boolean, 'category' => 'insight', 'requires' => ['reports_standard']],
    ]]]));
}

function planOffersService(): PlanOffers
{
    return new PlanOffers(planOffersCatalog(), new UsageCatalog(new Repository(['usage' => []])));
}

/** @param list<string> $codes */
function planOffersPlan(int $id, int $sortOrder, ?int $monthly, ?int $yearly = null, array $codes = []): Plan
{
    $plan = new Plan;
    $plan->setRawAttributes([
        'id' => $id,
        'sort_order' => $sortOrder,
        'price_minor' => $monthly ?? $yearly ?? 0,
        'monthly_price_minor' => $monthly,
        'yearly_price_minor' => $yearly,
        // A plan billed on neither cycle offers no comparable price.
        'billing_period' => $monthly !== null ? 'monthly' : ($yearly !== null ? 'yearly' : 'quarterly'),
        'currency' => 'IQD',
    ], true);
    $plan->setRelation('entitlements', new Collection(array_map(static function (string $code): PlanEntitlement {
        $row = new PlanEntitlement;
        $row->setRawAttributes(['entitlement' => $code], true);

        return $row;
    }, $codes)));

    return $plan;
}

it('orders plans commercially and totally, whatever order they arrive in', function (): void {
    $plans = [
        planOffersPlan(7, 2, 150000),
        planOffersPlan(3, 1, 90000),
        // Same sort order, cheaper per month through its yearly price only.
        planOffersPlan(9, 1, null, 600000),
        // Same sort order and price as #3: the id breaks the tie.
        planOffersPlan(2, 1, 90000),
        // No price at all sorts last within its sort order.
        planOffersPlan(5, 1, null, null),
        planOffersPlan(1, 0, 500000),
    ];

    $expected = [1, 9, 2, 3, 5, 7];

    for ($i = 0; $i < 12; $i++) {
        shuffle($plans);
        $order = array_map(static fn (Plan $plan): int => (int) $plan->getKey(), PlanOffers::inCommercialOrder($plans));
        expect($order)->toBe($expected);
    }
});

it('sells a feature only with what it depends on', function (): void {
    $offers = planOffersService();

    expect($offers->effectiveCodes(planOffersPlan(1, 1, 1000, null, ['finance'])))->toBe([])
        ->and($offers->effectiveCodes(planOffersPlan(2, 1, 1000, null, ['pos', 'finance', 'payments'])))->toBe(['pos', 'finance', 'payments'])
        ->and($offers->effectiveCodes(planOffersPlan(3, 1, 1000, null, ['reports_advanced', 'booking'])))->toBe(['booking'])
        // Unknown codes never count as sold.
        ->and($offers->effectiveCodes(planOffersPlan(4, 1, 1000, null, ['booking', 'teleportation'])))->toBe(['booking']);
});

it('answers the same effective set every time for the same plan', function (): void {
    $offers = planOffersService();
    $plan = planOffersPlan(8, 1, 1000, null, ['reports_standard', 'reports_advanced', 'pos']);

    expect($offers->effectiveCodes($plan))->toBe($offers->effectiveCodes($plan))
        ->and($offers->effectiveCodes($plan))->toBe(['reports_standard', 'reports_advanced', 'pos']);
});
