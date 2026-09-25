<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\SaveProduct;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Sales\Application\Actions\SetBranchInvoicePrefix;
use App\Modules\Sales\Application\TillCatalog;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\InvoicePrefix;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POS settings: the products the till sells, and where each branch's invoice
 * numbers start.
 *
 * Products are named once per ENABLED content language (a language switched
 * off keeps its stored text) and priced in the center's currency, parsed from
 * what was typed — never through a float. No stock: inventory is its own
 * capability (docs/18-SALES.md §6). A price change never reaches a sale that
 * already charged the old one; lines are snapshots.
 *
 * The invoice prefix applies to invoices issued from now on; one printed on
 * another branch's invoices is refused (docs/18-SALES.md §17). Both need `pos`.
 */
#[Layout('components.layouts.app')]
final class PosSettings extends Component
{
    use GuardsMoneyActions;
    use RequiresFeature;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $showArchived = false;

    public bool $editingProduct = false;

    /** The product being edited, or '' for a new one. */
    public string $productUuid = '';

    /** @var array<string, string> locale => name */
    public array $productNames = [];

    public string $productPrice = '';

    public string $productBarcode = '';

    public string $productSku = '';

    public bool $productActive = true;

    /** @var array<string, string> branch uuid => typed prefix */
    public array $prefixes = [];

    public string $error = '';

    public string $saved = '';

    public function mount(): void
    {
        $this->loadPrefixes();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowArchived(): void
    {
        $this->resetPage();
    }

    public function newProduct(): void
    {
        $this->reset(['productUuid', 'productNames', 'productPrice', 'productBarcode', 'productSku', 'error', 'saved']);
        $this->productActive = true;
        $this->editingProduct = true;
    }

    public function editProduct(string $uuid, TillCatalog $catalog): void
    {
        $this->attempt(function () use ($uuid, $catalog): void {
            $product = $catalog->product($uuid) ?? throw new NotFoundHttpException;

            $this->productUuid = $product->uuid;
            $this->productNames = array_map('strval', $product->name->all());
            $this->productPrice = $product->price(Currency::default())->toMajorString();
            $this->productBarcode = (string) $product->barcode;
            $this->productSku = (string) $product->sku;
            $this->productActive = $product->is_active;
            $this->editingProduct = true;
        });
    }

    public function closeProduct(): void
    {
        $this->reset(['editingProduct', 'productUuid', 'productNames', 'productPrice', 'productBarcode', 'productSku']);
        $this->resetValidation();
    }

    public function saveProduct(SaveProduct $save, TillCatalog $catalog, TenantLocales $locales): void
    {
        $saved = $this->attempt(function () use ($save, $catalog, $locales): void {
            try {
                // A parse of what was typed, in the center's currency.
                $price = Money::fromMajorString($this->productPrice, Currency::default())->minor;
            } catch (InvalidArgumentException) {
                throw SaleFailed::policy('Enter a price like 12000.');
            }

            $product = $this->productUuid === '' ? null : ($catalog->product($this->productUuid) ?? throw new NotFoundHttpException);

            // Stored text in a language that is switched off stays as it is.
            $names = $product?->name->all() ?? [];

            foreach ($locales->enabled() as $locale) {
                $names[$locale] = trim((string) ($this->productNames[$locale] ?? ''));
            }

            $save([
                'name' => $names,
                'sku' => $this->productSku === '' ? null : $this->productSku,
                'barcode' => $this->productBarcode === '' ? null : $this->productBarcode,
                'price_minor' => $price,
                'is_active' => $this->productActive,
                'sort_order' => $product->sort_order ?? 0,
            ], $this->user(), $product);

            $this->saved = (string) __($product === null ? 'Product added.' : 'manager_pos.settings.product_saved');
        });

        if ($saved) {
            $this->closeProduct();
        }
    }

    public function archiveProduct(string $uuid, SaveProduct $save, TillCatalog $catalog): void
    {
        $this->attempt(function () use ($uuid, $save, $catalog): void {
            $save->archive($catalog->product($uuid) ?? throw new NotFoundHttpException, $this->user());
            $this->saved = (string) __('Product archived.');
        });
    }

    public function savePrefix(string $branchUuid, SetBranchInvoicePrefix $set): void
    {
        $this->attempt(function () use ($branchUuid, $set): void {
            $typed = trim((string) ($this->prefixes[$branchUuid] ?? ''));
            $branch = $set($branchUuid, $this->user(), $typed === '' ? null : $typed);

            $this->prefixes[$branchUuid] = (string) $branch->invoice_prefix;
            $this->saved = (string) __('Invoice prefix saved. It applies to invoices issued from now on.');
        });
    }

    public function render(TillCatalog $catalog, TenantLocales $locales, LanguageRegistry $languages): View
    {
        if ($locked = $this->lockedView('pos')) {
            return $locked;
        }

        $user = $this->user();
        $manageProducts = $user->hasPermission(Permission::ProductManage);
        $manageBranches = $user->hasPermission(Permission::BranchManage);

        $products = [];
        $paginator = null;

        if ($manageProducts) {
            $paginator = $catalog->productsPage($this->search, $this->showArchived, $this->getPage());
            $products = array_map(static fn (Product $product): array => [
                'uuid' => $product->uuid,
                'name' => $product->name->get(),
                'price' => $product->price(Currency::default())->formatted(),
                'barcode' => $product->barcode,
                'sku' => $product->sku,
                'active' => $product->is_active,
                'archived' => $product->archived_at !== null,
            ], array_values($paginator->items()));
        }

        $primary = $locales->default();

        return view('livewire.center.pos-settings', [
            'products' => $products,
            'paginator' => $paginator,
            'branches' => $manageBranches ? $this->branchRows() : [],
            'productLocales' => array_map(static fn (string $locale): array => [
                'locale' => $locale,
                'label' => $languages->shortLabel($locale).' · '.$languages->nativeName($locale),
                'dir' => $languages->direction($locale),
                'primary' => $locale === $primary,
            ], $locales->enabled()),
            'currency' => Currency::default()->value,
            'canProducts' => $manageProducts,
            'canPrefix' => $manageBranches,
        ]);
    }

    /**
     * @return list<array{uuid: string, name: string, is_main: bool, prefix: string|null, effective: string}>
     */
    private function branchRows(): array
    {
        $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
        $this->user()->branchScope()->applyTo($query, 'id');

        return array_map(static fn (Branch $branch): array => [
            'uuid' => $branch->uuid,
            'name' => $branch->name->get(),
            'is_main' => $branch->is_main,
            'prefix' => $branch->invoice_prefix,
            // What the next invoice starts with today, or nothing yet.
            'effective' => $branch->invoice_prefix !== null || $branch->is_main ? InvoicePrefix::effective($branch->invoice_prefix, $branch->is_main) : '',
        ], $query->get()->all());
    }

    private function loadPrefixes(): void
    {
        foreach ($this->branchRows() as $branch) {
            $this->prefixes[$branch['uuid']] = (string) $branch['prefix'];
        }
    }
}
