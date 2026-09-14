<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\SaveProduct;
use App\Modules\Catalog\Domain\Models\Product;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\RotateInvoiceLink;
use App\Modules\Sales\Application\Actions\SetBranchInvoicePrefix;
use App\Modules\Sales\Application\InvoiceLinks;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A branch's sales for one day: what was issued, what was voided, what is still
 * an open draft — and the invoice behind each.
 *
 * NOT a finance report. No revenue breakdown, no payment method, no margin:
 * those are Phase 10. This is the list a manager opens to find a sale and void
 * it (docs/18-SALES.md §45).
 *
 * ## Readable without `pos`
 *
 * Sales and invoices already issued are history, and a center that loses POS
 * keeps reading them — the downgrade rule Booking set (docs/05-ENTITLEMENTS.md
 * §6.2). Voiding, products and the invoice prefix are operations and need
 * `pos`; reissuing a customer link does not, because revoking a leaked link is
 * a security control over a document already published.
 */
#[Layout('components.layouts.app')]
final class Sales extends Component
{
    #[Url]
    public string $branch = '';

    #[Url]
    public string $date = '';

    #[Url]
    public string $status = '';

    public string $open = '';

    public string $voidReason = '';

    // ---- products and the branch invoice prefix (managers) ---------------

    public string $productName = '';

    public string $productPrice = '';

    public string $productBarcode = '';

    public string $productSku = '';

    public string $invoicePrefix = '';

    /** The URL of a link reissued on this screen just now. Never stored. */
    #[Locked]
    public string $customerLink = '';

    public string $error = '';

    public string $saved = '';

    public function mount(): void
    {
        if ($this->branch === '') {
            $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
            $this->user()->branchScope()->applyTo($query, 'id');
            $this->branch = (string) ($query->value('uuid') ?? '');
        }
    }

    public function show(string $uuid): void
    {
        $this->open = $uuid;
        $this->reset(['voidReason', 'error', 'saved', 'customerLink']);
    }

    public function void(CloseSale $close, SalesQuery $query): void
    {
        $this->guard(function () use ($close, $query): void {
            $close->void($query->find($this->open, $this->user()), $this->user(), $this->voidReason);

            $this->reset(['voidReason']);
            $this->saved = __('Sale voided. Its invoice is kept, marked void.');
        });
    }

    public function rotateLink(RotateInvoiceLink $rotate, SalesQuery $query, InvoiceLinks $links): void
    {
        $this->guard(function () use ($rotate, $query, $links): void {
            $sale = $query->find($this->open, $this->user());

            if ($sale->invoice === null) {
                throw SaleFailed::policy(__('That sale has no invoice yet.'));
            }

            $this->customerLink = $links->url($rotate($sale->invoice, $this->user()));

            $this->saved = __('A new customer link was issued. Any earlier link no longer works.');
        });
    }

    public function addProduct(SaveProduct $save): void
    {
        $this->guard(function () use ($save): void {
            try {
                // A parse of what was typed, in the center's currency — never
                // a float, never arithmetic.
                $price = Money::fromMajorString($this->productPrice, Currency::default())->minor;
            } catch (InvalidArgumentException) {
                throw SaleFailed::policy(__('Enter a price like 12000.'));
            }

            $save([
                'name' => ['en' => $this->productName, 'ar' => $this->productName, 'ckb' => $this->productName],
                'sku' => $this->productSku === '' ? null : $this->productSku,
                'barcode' => $this->productBarcode === '' ? null : $this->productBarcode,
                'price_minor' => $price,
            ], $this->user());

            $this->reset(['productName', 'productPrice', 'productBarcode', 'productSku']);
            $this->saved = __('Product added.');
        });
    }

    public function archiveProduct(string $uuid, SaveProduct $save): void
    {
        $this->guard(function () use ($uuid, $save): void {
            /** @var Product|null $product */
            $product = Product::query()->where('uuid', $uuid)->first();

            if (! $product instanceof Product) {
                throw new NotFoundHttpException;
            }

            $save->archive($product, $this->user());
            $this->saved = __('Product archived.');
        });
    }

    public function setInvoicePrefix(SetBranchInvoicePrefix $set): void
    {
        $this->guard(function () use ($set): void {
            $branch = $set($this->branch, $this->user(), $this->invoicePrefix === '' ? null : $this->invoicePrefix);

            $this->invoicePrefix = (string) $branch->invoice_prefix;
            $this->saved = __('Invoice prefix saved. It applies to invoices issued from now on.');
        });
    }

    public function render(SalesQuery $query, SalesPresenter $presenter, Entitlements $entitlements): mixed
    {
        $user = $this->user();

        $branchQuery = Branch::query()->active();
        $user->branchScope()->applyTo($branchQuery, 'id');

        $sales = [];
        $detail = null;

        try {
            if ($this->branch !== '') {
                $sales = array_map(
                    fn (Sale $sale): array => $presenter->sale($sale, $user, withLines: false),
                    $query->forBranch($user, $this->branch, $this->status === '' ? null : $this->status, $this->date === '' ? null : $this->date),
                );
            }

            if ($this->open !== '') {
                $detail = $presenter->sale($query->find($this->open, $user), $user);
            }
        } catch (AuthorizationException|EntitlementRequired|NotFoundHttpException $e) {
            $this->error = $e instanceof NotFoundHttpException ? __('That sale could not be found.') : $e->getMessage();
        }

        $hasPos = $entitlements->enabled('pos');
        $canManageProducts = $hasPos && $user->hasPermission(Permission::ProductManage);

        return view('livewire.center.sales', [
            'branches' => $branchQuery->get(),
            'sales' => $sales,
            'detail' => $detail,
            'products' => $canManageProducts ? Product::query()->sellable()->limit(100)->get() : collect(),
            'currentPrefix' => $this->branch === '' ? null : Branch::query()->where('uuid', $this->branch)->value('invoice_prefix'),
            'hasPos' => $hasPos,
            'canVoid' => $hasPos && $user->hasPermission(Permission::SaleVoid),
            'canReissueLink' => $user->hasPermission(Permission::SaleFinalize),
            'canPrint' => $user->hasPermission(Permission::InvoicePrint) && $entitlements->enabled('printing'),
            'canManageProducts' => $canManageProducts,
            'canSetPrefix' => $hasPos && $user->hasPermission(Permission::BranchManage),
        ]);
    }

    private function guard(callable $work): void
    {
        $this->reset(['error', 'saved']);

        try {
            $work();
        } catch (SaleFailed|AuthorizationException|EntitlementRequired|ValidationException $failure) {
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
