<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyPresenter;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Memberships\Application\MembershipsPresenter;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Packages\Application\PackagesPresenter;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use Carbon\CarbonImmutable;

/**
 * A customer's OWN benefits — points, memberships, packages — for the customer
 * API and the customer's account page alike.
 *
 * Composed here, in the HTTP layer, because it spans three modules and the
 * Customers module may not know any of them. Every part is an allow-list from
 * its module's `forCustomer()` presenter: names, what is left, when it ends —
 * never a sale, an id, a staff name or a reason
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §19).
 *
 * READ-ONLY: nothing here repairs or activates. Memberships shown are the ones
 * in force or about to start; packages, the ones still active. Loyalty appears
 * when the center runs it, or when the customer already has points history.
 */
final class CustomerBenefits
{
    public function __construct(
        private readonly LoyaltyAccess $loyaltyAccess,
        private readonly LoyaltyQuery $loyalty,
        private readonly LoyaltyPresenter $loyaltyPresenter,
        private readonly MembershipsQuery $memberships,
        private readonly MembershipsPresenter $membershipsPresenter,
        private readonly PackagesQuery $packages,
        private readonly PackagesPresenter $packagesPresenter,
    ) {}

    /**
     * @return array{loyalty: array<string, mixed>|null, memberships: list<array<string, mixed>>, packages: list<array<string, mixed>>}
     */
    public function for(int $customerId): array
    {
        $now = CarbonImmutable::now();
        $account = $this->loyalty->accountOf($customerId);
        $program = $this->loyalty->currentProgram();

        $showLoyalty = $account !== null || ($this->loyaltyAccess->enabled() && $program !== null);

        $memberships = array_values(array_filter(
            $this->memberships->ofCustomer($customerId),
            static fn (CustomerMembership $membership): bool => in_array($membership->state($now), ['active', 'upcoming'], true),
        ));

        $packages = array_values(array_filter(
            $this->packages->ofCustomer($customerId),
            static fn (CustomerPackage $package): bool => $package->state($now) === 'active',
        ));

        return [
            'loyalty' => $showLoyalty ? $this->loyaltyPresenter->forCustomer($account, $program) : null,
            'memberships' => $this->membershipsPresenter->forCustomer($memberships),
            'packages' => $this->packagesPresenter->forCustomer($packages),
        ];
    }
}
