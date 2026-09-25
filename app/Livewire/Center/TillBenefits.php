<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Modules\Loyalty\Application\Actions\RedeemPoints;
use App\Modules\Loyalty\Application\LoyaltyPresenter;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Memberships\Application\Actions\ApplyMembershipBenefit;
use App\Modules\Memberships\Application\MembershipsPresenter;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Packages\Application\Actions\ApplyPackage;
use App\Modules\Packages\Application\PackagesPresenter;
use App\Modules\Packages\Application\PackagesQuery;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Offerings;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Enums\SaleItemKind;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The till's benefits panel, embedded under a draft sale: sell a membership or
 * a package as a line, redeem the customer's points, cover a service line with
 * their package or give them their member's price — and take any of it back.
 *
 * Its own component, so the till itself imports none of the benefit modules.
 * Every button calls the benefit module's Action, which goes through Sales'
 * benefit seam; nothing here prices, counts or decides. After a change it tells
 * the till to re-read the sale (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §27).
 *
 * Reading never repairs anything; using a benefit syncs the customer first,
 * inside the Action (§1).
 */
final class TillBenefits extends Component
{
    use GuardsMoneyActions;

    #[Locked]
    public string $sale = '';

    public string $offering = '';

    public string $points = '';

    /** @var array<string, string> line uuid → `package:{uuid}` or `membership:{benefit uuid}` */
    public array $coverWith = [];

    /**
     * Line uuid → the person confirms that service was performed. Needed for a
     * package on a line typed at the till; a visit line proves it by its own
     * completed stage (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §16).
     *
     * @var array<string, bool>
     */
    public array $performed = [];

    public string $error = '';

    public string $saved = '';

    public function addOffering(AddSaleLine $add, SalesQuery $sales): void
    {
        $this->run(function () use ($add, $sales): void {
            [$type, $reference] = array_pad(explode(':', $this->offering, 2), 2, '');

            $add($sales->find($this->sale, $this->user()), $this->user(), ['kind' => 'offering', 'offering_type' => $type, 'offering' => $reference]);

            $this->offering = '';
            $this->saved = (string) __('Added to the sale.');
        });
    }

    public function redeem(RedeemPoints $redeem, SalesQuery $sales): void
    {
        $this->run(function () use ($redeem, $sales): void {
            $redeem->apply($sales->find($this->sale, $this->user()), $this->user(), (int) $this->points);

            $this->points = '';
            $this->saved = (string) __('Points redeemed.');
        });
    }

    public function withdrawPoints(RedeemPoints $redeem, SalesQuery $sales): void
    {
        $this->run(function () use ($redeem, $sales): void {
            $redeem->withdraw($sales->find($this->sale, $this->user()), $this->user());
            $this->saved = (string) __('Points given back.');
        });
    }

    /**
     * Covers one service line with what was chosen for it.
     */
    public function cover(string $lineUuid, ApplyPackage $package, ApplyMembershipBenefit $membership, SalesQuery $sales): void
    {
        $this->run(function () use ($lineUuid, $package, $membership, $sales): void {
            [$kind, $uuid] = array_pad(explode(':', $this->coverWith[$lineUuid] ?? '', 2), 2, '');
            $sale = $sales->find($this->sale, $this->user());

            match ($kind) {
                'package' => $package->apply($sale, $this->user(), $lineUuid, $uuid, 1, (bool) ($this->performed[$lineUuid] ?? false)),
                'membership' => $membership->apply($sale, $this->user(), $lineUuid, $uuid),
                default => throw SaleFailed::policy('Choose a package or a membership benefit.'),
            };

            unset($this->coverWith[$lineUuid], $this->performed[$lineUuid]);
            $this->saved = (string) __('Benefit applied.');
        });
    }

    public function uncover(string $lineUuid, string $source, ApplyPackage $package, ApplyMembershipBenefit $membership, SalesQuery $sales): void
    {
        $this->run(function () use ($lineUuid, $source, $package, $membership, $sales): void {
            $sale = $sales->find($this->sale, $this->user());

            match ($source) {
                ApplyPackage::SOURCE => $package->withdraw($sale, $this->user(), $lineUuid),
                ApplyMembershipBenefit::SOURCE => $membership->withdraw($sale, $this->user(), $lineUuid),
                default => throw SaleFailed::policy('That is not a benefit this panel manages.'),
            };

            $this->saved = (string) __('Benefit withdrawn.');
        });
    }

    public function render(
        SalesQuery $sales,
        Offerings $offerings,
        LoyaltyQuery $loyalty,
        LoyaltyPresenter $loyaltyPresenter,
        PackagesQuery $packages,
        PackagesPresenter $packagesPresenter,
        MembershipsQuery $memberships,
        MembershipsPresenter $membershipsPresenter,
        Entitlements $entitlements,
    ): View {
        $user = $this->user();
        $now = CarbonImmutable::now();

        $view = [
            'draft' => false,
            'offerings' => [],
            'loyalty' => null,
            'redeemed' => null,
            'coverOptions' => [],
            'lines' => [],
        ];

        try {
            $sale = $sales->find($this->sale, $user);
            $view['draft'] = $sale->status === SaleStatus::Draft;

            if ($view['draft']) {
                $view['offerings'] = $this->offeringsFor($offerings, $user);
            }

            $benefitByLine = [];

            foreach ($sale->adjustments as $adjustment) {
                /** @var SaleAdjustment $adjustment */
                if ($adjustment->type !== AdjustmentType::BenefitDiscount) {
                    continue;
                }

                if ($adjustment->sale_item_id === null) {
                    $view['redeemed'] = ['amount' => $sale->money($adjustment->amount_minor)->toArray(app()->getLocale()), 'label' => $adjustment->reason];
                } else {
                    $benefitByLine[$adjustment->sale_item_id] = ['source' => (string) $adjustment->source_type, 'label' => $adjustment->reason];
                }
            }

            $view['lines'] = array_values($sale->items
                ->filter(static fn (SaleItem $item): bool => $item->kind === SaleItemKind::Service)
                ->map(static fn (SaleItem $item): array => [
                    'uuid' => $item->uuid,
                    'name' => $item->name->get(app()->getLocale()),
                    'quantity' => $item->quantity,
                    // A performed stage of the visit proves itself; a line
                    // typed here does not (§16).
                    'from_visit' => $item->journey_stage_id !== null,
                    'benefit' => $benefitByLine[(int) $item->getKey()] ?? null,
                ])->all());

            $customerId = $sale->customer_id;

            if ($customerId !== null && $user->hasPermission(Permission::LoyaltyView)) {
                $view['loyalty'] = $loyaltyPresenter->forCustomer($loyalty->accountOf($customerId), $loyalty->currentProgram());
            }

            $packageRows = [];
            $membershipRows = [];

            if ($customerId !== null && $user->hasPermission(Permission::PackageView)) {
                $active = array_values(array_filter($packages->ofCustomer($customerId), static fn (CustomerPackage $p): bool => $p->isUsable($now)));
                $packageRows = $packagesPresenter->forStaff($active);
            }

            if ($customerId !== null && $user->hasPermission(Permission::MembershipView)) {
                $inForce = array_values(array_filter($memberships->ofCustomer($customerId), static fn (CustomerMembership $m): bool => $m->isUsable($now)));
                $membershipRows = $membershipsPresenter->forStaff($inForce);
            }

            $view['coverOptions'] = $this->coverOptions($packageRows, $membershipRows);
        } catch (AuthorizationException|NotFoundHttpException) {
            // The till decides who sees the sale; this panel simply stays empty.
        }

        $view['canRedeem'] = $view['draft'] && is_array($view['loyalty'])
            && (int) ($view['loyalty']['point_value_minor'] ?? 0) > 0
            && (int) ($view['loyalty']['available_points'] ?? 0) > 0;
        // A center that sells none of the three has no benefits panel at all.
        $view['available'] = $entitlements->enabled('loyalty') || $entitlements->enabled('memberships') || $entitlements->enabled('packages')
            || $view['redeemed'] !== null || $view['coverOptions'] !== [];
        $view['hasContent'] = $view['offerings'] !== [] || $view['loyalty'] !== null || ($view['lines'] !== [] && $view['coverOptions'] !== []);

        return view('livewire.center.till-benefits', $view);
    }

    /**
     * One select option per usable package or membership benefit, labelled here
     * so the view prints a string.
     *
     * @param  list<array<string, mixed>>  $packages
     * @param  list<array<string, mixed>>  $memberships
     * @return list<array{value: string, label: string}>
     */
    private function coverOptions(array $packages, array $memberships): array
    {
        $options = [];

        foreach ($packages as $package) {
            $left = array_map(
                static fn (array $item): string => __('manager_pos.benefits.sessions_left', ['name' => $item['name'], 'left' => $item['left'], 'of' => $item['allocated']]),
                $package['items'] ?? [],
            );

            $options[] = [
                'value' => 'package:'.$package['uuid'],
                'label' => __('Package').': '.$package['name'].($left === [] ? '' : ' ('.implode(', ', $left).')'),
            ];
        }

        foreach ($memberships as $membership) {
            foreach ($membership['benefits'] ?? [] as $benefit) {
                $what = $benefit['all_services'] ? __('every service') : (string) $benefit['service_name'];
                $value = $benefit['percent'] !== null ? $benefit['percent'].'%' : (string) ($benefit['amount']['formatted'] ?? '');
                $uses = $benefit['uses_left'] !== null ? ' ('.__(':n left', ['n' => $benefit['uses_left']]).')' : '';

                $options[] = [
                    'value' => 'membership:'.$benefit['uuid'],
                    'label' => __('Membership').': '.$membership['name'].' — '.$what.' '.$value.$uses,
                ];
            }
        }

        return $options;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function offeringsFor(Offerings $offerings, User $user): array
    {
        try {
            return array_map(static fn (array $o): array => [
                'key' => $o['type'].':'.$o['reference'],
                'label' => ($o['type'] === 'membership' ? __('Membership') : __('Package')).': '.$o['name']->get(app()->getLocale())
                    .' — '.Money::fromMinor($o['unit_price_minor'], Currency::default())->formatted(),
            ], $offerings->availableFor($user));
        } catch (AuthorizationException|EntitlementRequired) {
            return [];
        }
    }

    private function run(callable $work): void
    {
        if ($this->attempt($work)) {
            $this->dispatch('sale-changed');
        }
    }
}
