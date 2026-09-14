<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Application\Actions\CheckoutJourney;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Application\CheckoutReview;
use App\Modules\Sales\Application\InvoiceLinks;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The till: build a cart, check a visit out, publish the invoice.
 *
 * ## Nothing here prices anything
 *
 * Every button calls the Action the API calls. The cart's totals come back from
 * `SalePricing` through the sale row; this component never adds, multiplies or
 * rounds an amount. The only conversion it performs is parsing what a person
 * TYPED — "12.5", "25000" — into the integer the Action validates, through
 * `Money::fromMajorString()` and `SalePricing::basisPoints()`, never a float
 * (docs/18-SALES.md §§9, 43).
 *
 * ## A visit arrives as `?journey=`
 *
 * The visit board links here with the journey's uuid. Mounting calls
 * `CheckoutJourney`, which returns the visit's draft or creates it — so the
 * board imports nothing from Sales, and a second click lands on the same cart.
 * The cart can be previewed while the visit is in progress; it is finalized once
 * the visit is completed.
 *
 * ## The customer link is shown once
 *
 * Its secret is stored only as a digest, so the URL exists in the response to
 * the finalize that minted it and nowhere else. It is held in `customerLink`
 * for this screen; opening another sale forgets it (docs/18-SALES.md §20).
 *
 * ## The till needs `pos`
 *
 * Without it the screen says so and shows no cart. Sales and invoices already
 * issued stay readable on the Sales screen — the till is for new ones.
 */
#[Layout('components.layouts.app')]
final class PointOfSale extends Component
{
    #[Url]
    public string $branch = '';

    #[Url]
    public string $sale = '';

    #[Url]
    public string $journey = '';

    public string $search = '';

    // ---- a service being configured before it is added ---------------------

    public string $pickService = '';

    public string $pickVariation = '';

    /** @var list<string> */
    public array $pickAddons = [];

    public int $pickQuantity = 1;

    // ---- custom line, override, adjustment --------------------------------

    public string $customName = '';

    public string $customPrice = '';

    public string $customReason = '';

    public string $overrideLine = '';

    public string $overridePrice = '';

    public string $overrideReason = '';

    public string $adjustmentType = 'discount_percent';

    public string $adjustmentValue = '';

    public string $adjustmentReason = '';

    public string $customerSearch = '';

    public string $shiftNote = '';

    /** One token per "new sale" press: a double-click gets the same draft. */
    public string $newSaleToken = '';

    /** The URL minted by this screen's last finalize, if any. Never stored. */
    #[Locked]
    public string $customerLink = '';

    public string $error = '';

    public string $saved = '';

    public function mount(CheckoutJourney $checkout): void
    {
        $this->newSaleToken = (string) Str::uuid();

        if ($this->branch === '') {
            $this->branch = $this->defaultBranch();
        }

        if ($this->journey !== '' && $this->sale === '') {
            $this->guard(function () use ($checkout): void {
                /** @var ServiceJourney|null $journey */
                $journey = ServiceJourney::query()->with('appointment')->where('uuid', $this->journey)->first();

                if (! $journey instanceof ServiceJourney || ! $this->user()->canAccessBranch($journey->branchId())) {
                    throw new NotFoundHttpException;
                }

                $sale = $checkout($journey, $this->user());

                $this->sale = $sale->uuid;
                $this->branch = Branch::query()->whereKey($sale->branch_id)->value('uuid') ?? $this->branch;
            });
        }
    }

    public function newSale(CreateDraftSale $create): void
    {
        $this->guard(function () use ($create): void {
            $sale = $create($this->branch, $this->user(), null, $this->newSaleToken);

            $this->sale = $sale->uuid;
            $this->journey = '';
            $this->customerLink = '';
            $this->newSaleToken = (string) Str::uuid();
        });
    }

    public function openSale(string $uuid): void
    {
        $this->sale = $uuid;
        $this->journey = '';
        $this->reset(['error', 'saved', 'overrideLine', 'customerLink']);
    }

    public function pick(string $serviceUuid): void
    {
        $this->pickService = $serviceUuid;
        $this->reset(['pickVariation', 'pickAddons', 'pickQuantity']);
    }

    public function addService(AddSaleLine $add, SalesQuery $query): void
    {
        $this->guard(function () use ($add, $query): void {
            $add($query->find($this->sale, $this->user()), $this->user(), [
                'kind' => 'service',
                'service' => $this->pickService,
                'variation' => $this->pickVariation === '' ? null : $this->pickVariation,
                'addons' => array_values(array_filter($this->pickAddons, 'is_string')),
                'quantity' => max(1, $this->pickQuantity),
            ]);

            $this->reset(['pickService', 'pickVariation', 'pickAddons', 'pickQuantity']);
        });
    }

    public function addProduct(string $productUuid, AddSaleLine $add, SalesQuery $query): void
    {
        $this->guard(function () use ($productUuid, $add, $query): void {
            $add($query->find($this->sale, $this->user()), $this->user(), ['kind' => 'product', 'product' => $productUuid]);
        });
    }

    public function addStage(string $stageUuid, AddSaleLine $add, SalesQuery $query): void
    {
        $this->guard(function () use ($stageUuid, $add, $query): void {
            $add($query->find($this->sale, $this->user()), $this->user(), ['kind' => 'journey_stage', 'stage' => $stageUuid]);
        });
    }

    public function addCustom(AddSaleLine $add, SalesQuery $query): void
    {
        $this->guard(function () use ($add, $query): void {
            $sale = $query->find($this->sale, $this->user());

            $add($sale, $this->user(), [
                'kind' => 'custom',
                'name' => $this->customName,
                'unit_price_minor' => $this->minor($this->customPrice, $sale),
                'reason' => $this->customReason,
            ]);

            $this->reset(['customName', 'customPrice', 'customReason']);
        });
    }

    public function quantity(string $lineUuid, int $quantity, ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->guard(function () use ($lineUuid, $quantity, $change, $query): void {
            $change->update($query->find($this->sale, $this->user()), $lineUuid, $this->user(), ['quantity' => $quantity]);
        });
    }

    public function removeLine(string $lineUuid, ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->guard(function () use ($lineUuid, $change, $query): void {
            $change->remove($query->find($this->sale, $this->user()), $lineUuid, $this->user());
        });
    }

    public function overrideLinePrice(ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->guard(function () use ($change, $query): void {
            $sale = $query->find($this->sale, $this->user());

            $change->overridePrice($sale, $this->overrideLine, $this->user(), $this->minor($this->overridePrice, $sale), $this->overrideReason);

            $this->reset(['overrideLine', 'overridePrice', 'overrideReason']);
        });
    }

    public function clearOverride(string $lineUuid, ChangeSaleLine $change, SalesQuery $query): void
    {
        $this->guard(function () use ($lineUuid, $change, $query): void {
            $change->overridePrice($query->find($this->sale, $this->user()), $lineUuid, $this->user(), null, null);
        });
    }

    public function addAdjustment(AdjustSale $adjust, SalesQuery $query): void
    {
        $this->guard(function () use ($adjust, $query): void {
            $sale = $query->find($this->sale, $this->user());
            $type = AdjustmentType::tryFrom($this->adjustmentType) ?? AdjustmentType::DiscountFixed;

            $value = $type->isPercentage()
                ? SalePricing::basisPoints($this->adjustmentValue)
                : $this->minor($this->adjustmentValue, $sale);

            $adjust->add($sale, $this->user(), $type, $value, $this->adjustmentReason);

            $this->reset(['adjustmentValue', 'adjustmentReason']);
        });
    }

    public function removeAdjustment(string $uuid, AdjustSale $adjust, SalesQuery $query): void
    {
        $this->guard(function () use ($uuid, $adjust, $query): void {
            $adjust->remove($query->find($this->sale, $this->user()), $uuid, $this->user());
        });
    }

    public function attachCustomer(string $customerUuid, AdjustSale $adjust, SalesQuery $query): void
    {
        $this->guard(function () use ($customerUuid, $adjust, $query): void {
            $adjust->customer($query->find($this->sale, $this->user()), $this->user(), $customerUuid === '' ? null : $customerUuid);

            $this->reset(['customerSearch']);
        });
    }

    public function discard(CloseSale $close, SalesQuery $query): void
    {
        $this->guard(function () use ($close, $query): void {
            $close->discard($query->find($this->sale, $this->user()), $this->user());

            $this->reset(['sale', 'journey', 'customerLink']);
            $this->saved = __('Sale discarded.');
        });
    }

    public function finalize(FinalizeSale $finalize, SalesQuery $query, InvoiceLinks $links): void
    {
        $this->guard(function () use ($finalize, $query, $links): void {
            $issued = $finalize($query->find($this->sale, $this->user()), $this->user());

            if ($issued->replayed) {
                $this->saved = __('Invoice :number was already issued.', ['number' => $issued->invoice->number]);

                return;
            }

            $this->customerLink = $issued->shareToken === null ? '' : $links->url($issued->shareToken);
            $this->saved = __('Invoice :number issued.', ['number' => $issued->invoice->number]);
        });
    }

    public function openShift(ManageCashierShift $shifts): void
    {
        $this->guard(function () use ($shifts): void {
            $shifts->open($this->branch, $this->user(), $this->shiftNote === '' ? null : $this->shiftNote);

            $this->reset(['shiftNote']);
        });
    }

    public function closeShift(string $uuid, ManageCashierShift $shifts): void
    {
        $this->guard(function () use ($uuid, $shifts): void {
            /** @var CashierShift|null $shift */
            $shift = CashierShift::query()->where('uuid', $uuid)->first();

            if (! $shift instanceof CashierShift) {
                throw new NotFoundHttpException;
            }

            $shifts->close($shift, $this->user(), $this->shiftNote === '' ? null : $this->shiftNote);

            $this->reset(['shiftNote']);
            $this->saved = __('Shift closed.');
        });
    }

    public function render(SalesQuery $query, SalesPresenter $presenter, CheckoutReview $review, ManageCashierShift $shifts, Entitlements $entitlements): mixed
    {
        $user = $this->user();

        $branchQuery = Branch::query()->active();
        $user->branchScope()->applyTo($branchQuery, 'id');
        $branches = $branchQuery->get();

        /** @var Branch|null $branch */
        $branch = $branches->firstWhere('uuid', $this->branch);

        $sale = null;
        $saleModel = null;
        $checkout = [];
        $drafts = [];
        $shift = null;
        $shiftSummary = null;
        $shiftOpenedAt = '';
        $visitStatus = null;

        try {
            // The till opens new sales; without `pos` there is nothing to do
            // here. History is on the Sales screen.
            $entitlements->ensure('pos');

            if ($this->sale !== '') {
                $saleModel = $query->find($this->sale, $user);
                $sale = $presenter->sale($saleModel, $user);
                $checkout = $review->forSale($saleModel);
                $visitStatus = $saleModel->isDraft() ? $saleModel->journey?->status : null;
            }

            if ($branch instanceof Branch) {
                $drafts = array_map(
                    fn (Sale $draft): array => $presenter->sale($draft, $user, withLines: false),
                    $query->forBranch($user, $branch->uuid, 'draft', null, 20),
                );

                $current = $shifts->currentFor($user, $branch->id);

                if ($current instanceof CashierShift) {
                    $shift = $presenter->shift($current);
                    $shiftSummary = $query->shiftSummary($current);
                    // The branch's wall clock, never the server's.
                    $shiftOpenedAt = BranchClock::toLocal($current->opened_at->toImmutable()->utc(), $branch->timezone)->format('H:i');
                }
            }
        } catch (AuthorizationException|EntitlementRequired|NotFoundHttpException $e) {
            $this->error = $e instanceof NotFoundHttpException ? __('That sale could not be found.') : $e->getMessage();
            $sale = null;
            $checkout = [];
            $drafts = [];
            $shift = null;
            $shiftSummary = null;
        }

        $pick = null;

        if ($this->pickService !== '') {
            /** @var Service|null $pick */
            $pick = Service::query()
                ->where('uuid', $this->pickService)
                ->with(['variations' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order'), 'addons'])
                ->first();
        }

        return view('livewire.center.pointOfSale', [
            'branches' => $branches,
            'cart' => $sale,
            'checkout' => $checkout,
            'drafts' => $drafts,
            'shift' => $shift,
            'shiftSummary' => $shiftSummary,
            'shiftOpenedAt' => $shiftOpenedAt,
            'services' => $this->services($branch),
            'products' => $this->products(),
            'customers' => $this->customers(),
            'pick' => $pick,
            // A visit's draft is previewed while the visit runs, finalized once
            // it is over (FinalizeSale refuses otherwise).
            'visitInProgress' => $visitStatus === JourneyStatus::Active,
            'visitAbandoned' => $visitStatus === JourneyStatus::Aborted,
            'canCreate' => $user->hasPermission(Permission::SaleCreate),
            'canFinalize' => $user->hasPermission(Permission::SaleFinalize),
            'canAdjust' => $user->hasPermission(Permission::SaleAdjust),
            'canShift' => $user->hasPermission(Permission::CashierShiftManage),
            // A center with POS but without Printing still has the digital
            // invoice; it simply gets no print buttons (§23).
            'canPrint' => $user->hasPermission(Permission::InvoicePrint) && $entitlements->enabled('printing'),
        ]);
    }

    /**
     * @return list<Service>
     */
    private function services(?Branch $branch): array
    {
        if (! $branch instanceof Branch) {
            return [];
        }

        $query = Service::query()->active()->atBranch($branch->id)->limit(60);

        if ($this->search !== '') {
            $query->where('name', 'like', '%'.addcslashes($this->search, '%_\\').'%');
        }

        /** @var list<Service> $services */
        $services = $query->get()->all();

        return $services;
    }

    /**
     * @return list<Product>
     */
    private function products(): array
    {
        $query = Product::query()->sellable()->limit(60);

        if ($this->search !== '') {
            $term = trim($this->search);

            // A scanner types the whole barcode: match it exactly, first.
            $query->where(function ($q) use ($term): void {
                $q->where('barcode', $term)
                    ->orWhere('sku', $term)
                    ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%');
            });
        }

        /** @var list<Product> $products */
        $products = $query->get()->all();

        return $products;
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    private function customers(): array
    {
        $term = trim($this->customerSearch);

        if (mb_strlen($term) < 2 || ! $this->user()->hasPermission(Permission::CustomerView)) {
            return [];
        }

        return Customer::query()
            ->whereNull('archived_at')
            ->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')
            ->orderBy('name')
            ->limit(10)
            ->get(['uuid', 'name'])
            ->map(static fn (Customer $c): array => ['uuid' => $c->uuid, 'name' => $c->name])
            ->values()
            ->all();
    }

    /**
     * Parses a TYPED amount into minor units — the one conversion this screen
     * does, and it is a parse, not arithmetic.
     *
     * @throws SaleFailed
     */
    private function minor(string $typed, Sale $sale): int
    {
        try {
            return Money::fromMajorString($typed, Currency::from($sale->currency))->minor;
        } catch (InvalidArgumentException) {
            throw SaleFailed::policy(__('Enter an amount like 25000.'));
        }
    }

    private function defaultBranch(): string
    {
        $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
        $this->user()->branchScope()->applyTo($query, 'id');

        return (string) ($query->value('uuid') ?? '');
    }

    private function guard(callable $work): void
    {
        $this->reset(['error', 'saved']);

        try {
            $work();
        } catch (SaleFailed|AuthorizationException|EntitlementRequired $failure) {
            $this->error = $failure->getMessage();
        } catch (NotFoundHttpException) {
            $this->error = __('That could not be found.');
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }
}
