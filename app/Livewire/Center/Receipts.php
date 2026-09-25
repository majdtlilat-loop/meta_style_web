<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\PosFinance\BranchTime;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Livewire\Center\PosFinance\HasMoneyWindow;
use App\Livewire\Center\PosFinance\MoneyLabels;
use App\Livewire\Center\PosFinance\MoneyTabs;
use App\Livewire\Center\PosFinance\PaymentRows;
use App\Livewire\Center\PosFinance\Refusals;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Application\PaymentsPresenter;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\Payment;
use App\Modules\Payments\Domain\Models\Refund;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Receipts: every payment taken at a branch — cash, transfer, online — and
 * every refund, over whole branch-local days.
 *
 * Read-only and history: it needs `payment.view` and the branch, and stays
 * readable after a downgrade (docs/19-PAYMENTS.md §3). Only a center that can
 * take no payment at all and never took one sees the upgrade state.
 * The totals are what SUCCEEDED in the window, added up by the Payments module
 * per currency; an online payment still waiting for its provider is shown
 * apart, because it is not money received. Refunds and voids happen from the
 * sale's own money panel, one click away.
 */
#[Layout('components.layouts.app')]
final class Receipts extends Component
{
    use GuardsMoneyActions;
    use HasMoneyWindow;
    use RequiresFeature;
    use WithPagination;

    #[Url]
    public string $branch = '';

    #[Url(as: 'show')]
    public string $list = 'payments';

    #[Url]
    public string $method = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'q')]
    public string $term = '';

    public string $error = '';

    public string $saved = '';

    public function mount(): void
    {
        if ($this->branch === '') {
            $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
            $this->user()->branchScope()->applyTo($query, 'id');
            $this->branch = (string) ($query->value('uuid') ?? '');
        }

        $this->list = $this->list === 'refunds' ? 'refunds' : 'payments';
    }

    public function showList(string $list): void
    {
        $this->list = $list === 'refunds' ? 'refunds' : 'payments';
        $this->status = '';
        $this->windowChanged();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['branch', 'method', 'status', 'term'], true)) {
            $this->windowChanged();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['method', 'status', 'term', 'range', 'from', 'until']);
        $this->windowChanged();
    }

    protected function windowChanged(): void
    {
        $this->resetPage();
        $this->resetPage('refundsPage');
    }

    protected function defaultRange(): string
    {
        return 'today';
    }

    protected function windowTimezone(): string
    {
        return BranchTime::zoneOf(Branch::query()->where('uuid', $this->branch)->first());
    }

    public function render(PaymentsQuery $query, PaymentsPresenter $presenter, Entitlements $entitlements): View
    {
        // Receipts are history: readable after a downgrade. A center that can
        // take no payment at all (neither the desk nor online) and never took
        // one sees what the POS would give it.
        if (! $entitlements->enabled('pos') && ! $entitlements->enabled('payments') && ! $query->hasHistory() && ($locked = $this->lockedView('pos'))) {
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
        $filters = ['method' => $this->method, 'status' => $this->status, 'term' => $this->term];

        $rows = [];
        $paginator = null;
        $totals = null;
        $denied = ! $user->hasPermission(Permission::PaymentView);
        // A read-only page: its only message is about what it just read.
        $this->error = '';

        try {
            if (! $denied && $branch instanceof Branch) {
                $totals = $this->totals($query->receiptTotals($user, $branch->uuid, $from, $until));

                if ($this->list === 'refunds') {
                    $paginator = $query->refundsIn($user, $branch->uuid, $from, $until, $filters, $this->getPage('refundsPage'));
                    $rows = array_map(fn (Refund $refund): array => $this->refundRow($refund, $presenter, $timezone), array_values($paginator->items()));
                } else {
                    $paginator = $query->receipts($user, $branch->uuid, $from, $until, $filters, $this->getPage());
                    $rows = array_map(fn (Payment $payment): array => PaymentRows::payment($presenter->payment($payment), $timezone) + [
                        'sale' => $payment->invoice?->sale?->uuid,
                    ], array_values($paginator->items()));
                }
            }
        } catch (AuthorizationException|PaymentFailed $failure) {
            $this->error = Refusals::text($failure->getMessage());
        } catch (NotFoundHttpException) {
            $this->error = (string) __('That branch could not be found.');
        }

        return view('livewire.center.receipts', [
            'tabs' => MoneyTabs::for($user, 'receipts'),
            'window' => $this->windowControl($timezone),
            'branches' => array_map(static fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name->get()], $branches),
            'rows' => $rows,
            'paginator' => $paginator,
            'totals' => $totals,
            'denied' => $denied,
            'methods' => array_map(static fn (string $method): array => ['value' => $method, 'label' => MoneyLabels::method($method)], ['cash', 'manual_electronic', 'gateway']),
            'statuses' => array_map(
                fn (string $status): array => ['value' => $status, 'label' => $this->list === 'refunds' ? MoneyLabels::refundStatus($status) : MoneyLabels::paymentStatus($status)],
                $this->list === 'refunds' ? ['succeeded', 'pending', 'failed'] : ['succeeded', 'pending', 'failed', 'cancelled'],
            ),
            'hasFilters' => $this->method !== '' || $this->status !== '' || $this->term !== '',
            'onlineOwned' => $entitlements->enabled('payments'),
            'canOpenSales' => $user->hasPermission(Permission::SaleView),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function refundRow(Refund $refund, PaymentsPresenter $presenter, string $timezone): array
    {
        return PaymentRows::refund($presenter->refund($refund), $timezone) + [
            'invoice_number' => $refund->payment?->invoice?->number,
            'invoice' => $refund->payment?->invoice?->uuid,
            'sale' => $refund->payment?->invoice?->sale?->uuid,
            'payment_method_label' => $refund->payment === null ? null : MoneyLabels::method($refund->payment->method->value),
        ];
    }

    /**
     * @param  array{received: array{by_method: list<array{method: string, currency: string, total_minor: int, count: int}>, total: list<array{currency: string, total_minor: int, count: int}>}, refunded: array{by_method: list<array{method: string, currency: string, total_minor: int, count: int}>, total: list<array{currency: string, total_minor: int, count: int}>}, pending: array{by_method: list<array{method: string, currency: string, total_minor: int, count: int}>, total: list<array{currency: string, total_minor: int, count: int}>}}  $totals
     * @return array<string, mixed>
     */
    private function totals(array $totals): array
    {
        $count = static fn (array $rows): int => array_sum(array_column($rows, 'count'));

        return [
            'received' => MoneyLabels::amounts($totals['received']['total']),
            'received_count' => $count($totals['received']['total']),
            'by_method' => array_map(static fn (array $row): array => [
                'label' => MoneyLabels::method($row['method']),
                'amount' => MoneyLabels::amounts([$row]),
                'count' => $row['count'],
            ], $totals['received']['by_method']),
            'refunded' => MoneyLabels::amounts($totals['refunded']['total']),
            'refunded_count' => $count($totals['refunded']['total']),
            'pending' => $totals['pending']['total'] === [] ? null : MoneyLabels::amounts($totals['pending']['total']),
            'pending_count' => $count($totals['pending']['total']),
            'mixed' => count($totals['received']['total']) > 1 || count($totals['refunded']['total']) > 1,
        ];
    }
}
