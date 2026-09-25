<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\PosFinance\BranchTime;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Livewire\Center\PosFinance\HasMoneyWindow;
use App\Livewire\Center\PosFinance\MoneyTabs;
use App\Livewire\Center\PosFinance\Refusals;
use App\Livewire\Center\Till\TillView;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\RotateInvoiceLink;
use App\Modules\Sales\Application\InvoiceLinks;
use App\Modules\Sales\Application\SalesPresenter;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\Sale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A branch's sales history: find a sale by invoice number or customer, over
 * whole branch-local days, by status or by who issued it — and the invoice
 * behind each, with its money panel.
 *
 * NOT a finance report. No revenue, no margin: what moved is on Receipts and
 * Finance. This is the list a manager opens to find a sale, share its invoice
 * or void it (docs/18-SALES.md §45).
 *
 * ## Readable without `pos`
 *
 * Sales and invoices already issued are history, and a center that loses POS
 * keeps reading them — the downgrade rule Booking set (docs/05-ENTITLEMENTS.md
 * §6.2). A center that never sold anything sees the upgrade state instead.
 * Voiding is an operation and needs `pos`; reissuing a customer link does not,
 * because revoking a leaked link is a security control over a document already
 * published.
 */
#[Layout('components.layouts.app')]
final class Sales extends Component
{
    use GuardsMoneyActions;
    use HasMoneyWindow;
    use RequiresFeature;
    use WithPagination;

    #[Url]
    public string $branch = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $term = '';

    #[Url]
    public string $cashier = '';

    #[Url(as: 'sale')]
    public string $open = '';

    public string $voidReason = '';

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

    public function updated(string $property): void
    {
        if (in_array($property, ['branch', 'status', 'term', 'cashier'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['status', 'term', 'cashier', 'range', 'from', 'until']);
        $this->resetPage();
    }

    public function show(string $uuid): void
    {
        $this->open = $uuid;
        $this->reset(['voidReason', 'error', 'saved', 'customerLink']);
    }

    public function closeDetail(): void
    {
        $this->reset(['open', 'voidReason', 'customerLink']);
    }

    public function void(CloseSale $close, SalesQuery $query): void
    {
        $this->attempt(function () use ($close, $query): void {
            $close->void($query->find($this->open, $this->user()), $this->user(), $this->voidReason);

            $this->reset(['voidReason']);
            $this->saved = (string) __('Sale voided. Its invoice is kept, marked void.');
        });
    }

    public function rotateLink(RotateInvoiceLink $rotate, SalesQuery $query, InvoiceLinks $links): void
    {
        $this->attempt(function () use ($rotate, $query, $links): void {
            $sale = $query->find($this->open, $this->user());

            if ($sale->invoice === null) {
                throw SaleFailed::policy('That sale has no invoice yet.');
            }

            $this->customerLink = $links->url($rotate($sale->invoice, $this->user()));
            $this->saved = (string) __('A new customer link was issued. Any earlier link no longer works.');
        });
    }

    protected function defaultRange(): string
    {
        return 'today';
    }

    protected function windowTimezone(): string
    {
        return BranchTime::zoneOf(Branch::query()->where('uuid', $this->branch)->first());
    }

    public function render(SalesQuery $query, SalesPresenter $presenter, Entitlements $entitlements): View
    {
        $hasPos = $entitlements->enabled('pos');

        // History stays readable after a downgrade; a center with none has
        // nothing to read and sees what POS would give it.
        if (! $hasPos && ! $query->hasHistory() && ($locked = $this->lockedView('pos'))) {
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
        [$from, $until] = $this->windowDates($timezone);
        $filters = ['status' => $this->status, 'term' => $this->term, 'cashier' => $this->cashier];

        $sales = null;
        $rows = [];
        $totals = [];
        $cashiers = [];
        $detail = null;
        $listError = '';

        try {
            if ($branch instanceof Branch) {
                $sales = $query->history($user, $branch->uuid, $from, $until, $filters, $this->getPage());
                $rows = array_map(fn (Sale $sale): array => $this->row($presenter->sale($sale, $user, withLines: false), $timezone), array_values($sales->items()));
                $totals = $this->totals($query->historyTotals($user, $branch->uuid, $from, $until));
                $cashiers = $query->cashiers($user, $branch->uuid, $from, $until);
            }
        } catch (AuthorizationException|SaleFailed $failure) {
            $listError = Refusals::text($failure->getMessage());
        } catch (NotFoundHttpException) {
            $listError = (string) __('That branch could not be found.');
        }

        try {
            if ($this->open !== '') {
                $sale = $query->find($this->open, $user);
                $detail = $this->detail($presenter->sale($sale, $user), BranchTime::zoneOf($sale->branch));
            }
        } catch (AuthorizationException|NotFoundHttpException $e) {
            $this->error = $e instanceof NotFoundHttpException ? (string) __('That sale could not be found.') : Refusals::text($e->getMessage());
        }

        return view('livewire.center.sales', [
            'tabs' => MoneyTabs::for($user, 'sales'),
            'window' => $this->windowControl($timezone),
            'branches' => array_map(static fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name->get()], $branches),
            'sales' => $rows,
            'paginator' => $sales,
            'totals' => $totals,
            'cashiers' => $cashiers,
            'detail' => $detail,
            'listError' => $listError,
            'hasFilters' => $this->status !== '' || $this->term !== '' || $this->cashier !== '',
            'hasPos' => $hasPos,
            'offer' => $hasPos ? null : $this->lockedFeature('pos'),
            'canSell' => $hasPos && $user->hasPermission(Permission::SaleCreate),
            'canVoid' => $hasPos && $user->hasPermission(Permission::SaleVoid),
            'canReissueLink' => $user->hasPermission(Permission::SaleFinalize),
            'canPrint' => $user->hasPermission(Permission::InvoicePrint) && $entitlements->enabled('printing'),
            'canViewMoney' => $user->hasPermission(Permission::PaymentView),
        ]);
    }

    /**
     * @param  array<string, mixed>  $sale
     * @return array<string, mixed>
     */
    private function row(array $sale, string $timezone): array
    {
        return $sale + [
            'status_label' => TillView::statusLabel((string) $sale['status']),
            'when' => BranchTime::label($sale['finalized_at'] ?? $sale['created_at'] ?? null, $timezone, BranchTime::DATETIME),
            'cashier' => $sale['finalized_by'] ?? $sale['created_by'],
        ];
    }

    /**
     * @param  array<string, mixed>  $sale
     * @return array<string, mixed>
     */
    private function detail(array $sale, string $timezone): array
    {
        $sale = TillView::cart($sale, $timezone);
        $sale['created_at_label'] = BranchTime::label($sale['created_at'] ?? null, $timezone, BranchTime::DATETIME_FULL);
        $sale['voided_at_label'] = BranchTime::label($sale['voided_at'] ?? null, $timezone, BranchTime::DATETIME_FULL);

        return $sale;
    }

    /**
     * The strip above the list, per currency — never mixed.
     *
     * @param  list<array{status: string, currency: string, sales: int, total_minor: int}>  $rows
     * @return list<array{label: string, count: int, amount: string, tone: string|null}>
     */
    private function totals(array $rows): array
    {
        $totals = [];

        foreach ($rows as $row) {
            $voided = $row['status'] === 'voided';

            $totals[] = [
                'label' => (string) __($voided ? 'manager_pos.history.voided_total' : 'manager_pos.history.issued_total'),
                'count' => $row['sales'],
                'amount' => Money::fromMinor($row['total_minor'], Currency::tryFrom($row['currency']) ?? Currency::default())->formatted(),
                'tone' => $voided ? 'danger' : null,
            ];
        }

        return $totals;
    }
}
