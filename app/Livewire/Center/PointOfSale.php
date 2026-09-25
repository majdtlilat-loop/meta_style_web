<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\PosFinance\BranchTime;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Livewire\Center\PosFinance\Refusals;
use App\Livewire\Center\Till\EditsTillCart;
use App\Livewire\Center\Till\FindsTillCustomers;
use App\Livewire\Center\Till\ManagesTillShift;
use App\Livewire\Center\Till\TillView;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Sales\Application\Actions\CheckoutJourney;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Application\CheckoutReview;
use App\Modules\Sales\Application\InvoiceLinks;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Application\TillCatalog;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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
 * rounds an amount. It only PARSES what a person typed ({@see EditsTillCart}).
 *
 * ## A visit arrives as `?journey=`
 *
 * The visit board links here with the journey's uuid. Mounting calls
 * `CheckoutJourney`, which returns the visit's draft or creates it — so the
 * board imports nothing from Sales, and a second click lands on the same cart.
 *
 * ## The customer link is shown once
 *
 * Its secret is stored only as a digest, so the URL exists in the response to
 * the finalize that minted it and nowhere else (docs/18-SALES.md §20).
 *
 * ## The till needs `pos`
 *
 * Without it the page is the upgrade state and loads nothing. Sales already
 * issued stay readable on the Sales screen — the till is for new ones. Taking
 * the money is the embedded Payments panel; the benefits are their own panel.
 */
#[Layout('components.layouts.app')]
final class PointOfSale extends Component
{
    use EditsTillCart;
    use FindsTillCustomers;
    use GuardsMoneyActions;
    use ManagesTillShift;
    use RequiresFeature;

    #[Url]
    public string $branch = '';

    #[Url]
    public string $sale = '';

    #[Url]
    public string $journey = '';

    public string $search = '';

    public string $category = '';

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
            $this->attempt(function () use ($checkout): void {
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

    public function updatedBranch(): void
    {
        $this->reset(['category', 'pickService', 'pickVariation', 'pickAddons', 'pickQuantity']);
    }

    public function newSale(CreateDraftSale $create): void
    {
        $this->attempt(function () use ($create): void {
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
        $this->reset(['error', 'saved', 'overrideLine', 'customerLink', 'pickService', 'addingCustomer']);
    }

    public function closeSale(): void
    {
        $this->reset(['sale', 'journey', 'customerLink', 'overrideLine', 'pickService', 'addingCustomer']);
    }

    public function discard(CloseSale $close, SalesQuery $query): void
    {
        $this->attempt(function () use ($close, $query): void {
            $close->discard($query->find($this->sale, $this->user()), $this->user());

            $this->reset(['sale', 'journey', 'customerLink']);
            $this->saved = (string) __('Sale discarded.');
        });
    }

    public function finalize(FinalizeSale $finalize, SalesQuery $query, InvoiceLinks $links): void
    {
        $this->attempt(function () use ($finalize, $query, $links): void {
            $issued = $finalize($query->find($this->sale, $this->user()), $this->user());

            if ($issued->replayed) {
                $this->saved = (string) __('Invoice :number was already issued.', ['number' => $issued->invoice->number]);

                return;
            }

            $this->customerLink = $issued->shareToken === null ? '' : $links->url($issued->shareToken);
            $this->saved = (string) __('Invoice :number issued.', ['number' => $issued->invoice->number]);
        });
    }

    /**
     * The benefits panel changed the sale — a redemption, a covered line, a
     * membership or package added. Re-rendering re-reads it; the till itself
     * knows nothing about benefits.
     */
    #[On('sale-changed')]
    public function saleChanged(): void {}

    public function render(
        SalesQuery $query,
        SalesPresenter $presenter,
        CheckoutReview $review,
        ManageCashierShift $shifts,
        TillCatalog $catalog,
        CustomerQuery $customers,
        CustomerPresenter $customerPresenter,
        Entitlements $entitlements,
    ): View {
        // The till opens NEW sales: without `pos` it is the upgrade state and
        // loads no data. History is on the Sales screen.
        if ($locked = $this->lockedView('pos')) {
            return $locked;
        }

        $user = $this->user();

        $branchQuery = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
        $user->branchScope()->applyTo($branchQuery, 'id');
        /** @var list<Branch> $branches */
        $branches = $branchQuery->get()->all();

        $branch = null;

        foreach ($branches as $candidate) {
            if ($candidate->uuid === $this->branch) {
                $branch = $candidate;
            }
        }

        $timezone = BranchTime::zoneOf($branch);
        $cart = null;
        $checkout = [];
        $drafts = [];
        $shift = null;
        $visitStatus = null;

        try {
            if ($this->sale !== '') {
                $saleModel = $query->find($this->sale, $user);
                $cart = TillView::cart($presenter->sale($saleModel, $user), BranchTime::zoneOf($saleModel->branch));
                $checkout = TillView::checkout($review->forSale($saleModel));
                $visitStatus = $saleModel->isDraft() ? $saleModel->journey?->status : null;
            }

            if ($branch instanceof Branch) {
                $drafts = array_map(
                    fn (Sale $draft): array => $presenter->sale($draft, $user, withLines: false),
                    $query->forBranch($user, $branch->uuid, 'draft', null, 20),
                );

                $current = $shifts->currentFor($user, $branch->id);

                if ($current instanceof CashierShift) {
                    $summary = $query->shiftSummary($current);
                    $shift = [
                        'uuid' => $current->uuid,
                        // The branch's wall clock, never the server's.
                        'opened_at' => BranchTime::label($current->opened_at->toIso8601String(), $timezone, BranchTime::TIME),
                        'sales' => $summary['sales'],
                        'totals' => TillView::shiftTotals($summary),
                    ];
                }
            }
        } catch (AuthorizationException|NotFoundHttpException $e) {
            $this->error = $e instanceof NotFoundHttpException ? (string) __('That sale could not be found.') : Refusals::text($e->getMessage());
            $cart = null;
            $checkout = [];
        }

        $canCreate = $user->hasPermission(Permission::SaleCreate);
        $draft = $cart !== null && $cart['status'] === 'draft';

        return view('livewire.center.pointOfSale', [
            'branches' => array_map(static fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name->get()], $branches),
            'cart' => $cart,
            'draft' => $draft,
            'checkout' => $checkout,
            'drafts' => $drafts,
            'shift' => $shift,
            'categories' => $branch instanceof Branch && $draft && $canCreate ? $catalog->categories($branch) : [],
            'services' => $branch instanceof Branch && $draft && $canCreate ? $catalog->services($branch, $this->search, $this->category) : [],
            'products' => $draft && $canCreate ? $catalog->products($this->search) : [],
            'pick' => $branch instanceof Branch && $draft ? $catalog->pick($branch, $this->pickService) : null,
            'customers' => $draft && $canCreate ? $this->customerMatches($customers, $customerPresenter, $user) : [],
            'customerSearched' => mb_strlen(trim($this->customerSearch)) >= 2,
            // A visit's draft is previewed while the visit runs, finalized once
            // it is over (FinalizeSale refuses otherwise).
            'visitInProgress' => $visitStatus === JourneyStatus::Active,
            'visitAbandoned' => $visitStatus === JourneyStatus::Aborted,
            'canCreate' => $canCreate,
            'canFinalize' => $user->hasPermission(Permission::SaleFinalize),
            'canAdjust' => $user->hasPermission(Permission::SaleAdjust),
            'canShift' => $user->hasPermission(Permission::CashierShiftManage),
            'canSupervise' => $user->hasPermission(Permission::CashierShiftSupervise),
            'canAddCustomer' => $user->hasPermission(Permission::CustomerCreate),
            'canFindCustomer' => $user->hasPermission(Permission::CustomerView),
            'canSettings' => $user->hasPermission(Permission::ProductManage) || $user->hasPermission(Permission::BranchManage),
            // With Finance the drawer is counted at close (docs/20-FINANCE.md §33).
            'countsDrawer' => $entitlements->enabled('finance'),
            // The invoice's own money panel is a separate component: this one
            // builds and publishes the bill; collecting against it is Payments.
            'canViewMoney' => $user->hasPermission(Permission::PaymentView),
            // A center with POS but without Printing still has the digital
            // invoice; it simply gets no print buttons (§23).
            'canPrint' => $user->hasPermission(Permission::InvoicePrint) && $entitlements->enabled('printing'),
        ]);
    }

    private function defaultBranch(): string
    {
        $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
        $this->user()->branchScope()->applyTo($query, 'id');

        return (string) ($query->value('uuid') ?? '');
    }
}
