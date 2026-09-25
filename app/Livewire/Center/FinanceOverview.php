<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\PosFinance\BranchTime;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Livewire\Center\PosFinance\HasMoneyWindow;
use App\Livewire\Center\PosFinance\MoneyLabels;
use App\Livewire\Center\PosFinance\MoneyTabs;
use App\Livewire\Center\PosFinance\Refusals;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Finance\Application\FinanceDashboard;
use App\Modules\Finance\Application\FinancePresenter;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Finance\Domain\Exceptions\FinanceFailed;
use App\Modules\Finance\Domain\Models\FinanceEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Center finance at a glance: INVOICED, COLLECTED, REFUNDED, EXPENSES, NET.
 *
 * Operational, not analytics: a bounded window of branch-local days, a branch
 * filter, and figures that never blur an invoice issued with money received
 * (docs/20-FINANCE.md §§42–43). Every number comes from `FinanceDashboard`;
 * this component formats and colours. Currency is the center's.
 *
 * ## After a downgrade
 *
 * The dashboard needs `finance`. The ledger is history and stays readable:
 * a center that had Finance keeps its money movements (compact notice), one
 * that never had it sees the upgrade state.
 */
#[Layout('components.layouts.app')]
final class FinanceOverview extends Component
{
    use GuardsMoneyActions;
    use HasMoneyWindow;
    use RequiresFeature;

    #[Url]
    public string $branch = '';

    public string $error = '';

    public string $saved = '';

    protected function defaultRange(): string
    {
        return 'this_month';
    }

    /**
     * One branch: its own calendar. All branches: the main branch's, and the
     * page says so (docs/20-FINANCE.md §42).
     */
    protected function windowTimezone(): string
    {
        $query = Branch::query()->orderByDesc('is_main')->orderBy('id');

        if ($this->branch !== '') {
            $query->where('uuid', $this->branch);
        }

        return BranchTime::zoneOf($query->first());
    }

    public function render(FinanceDashboard $dashboard, FinanceQuery $query, FinancePresenter $presenter, Entitlements $entitlements): View
    {
        $this->error = '';
        $owned = $entitlements->enabled('finance');

        if (! $owned && ! $query->hasHistory()) {
            // Nothing to read: the upgrade state, and the dashboard's refusal.
            $this->error = self::entitlementRefusal(new EntitlementRequired('finance'));

            if ($locked = $this->lockedView('finance')) {
                return $locked;
            }
        }

        $user = $this->user();
        $branchQuery = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
        $user->branchScope()->applyTo($branchQuery, 'id');
        /** @var list<Branch> $branches */
        $branches = $branchQuery->get()->all();

        $timezone = $this->windowTimezone();
        [$from, $until] = $this->windowDates($timezone);

        $summary = null;
        $entries = [];

        try {
            if ($owned) {
                $summary = $this->present($dashboard->summary($user, $from, $until, $this->branch === '' ? null : $this->branch), $timezone);
            }

            if ($this->branch !== '') {
                $ledger = $query->ledger($user, $this->branch, $from, $until);
                $references = $query->references($ledger);
                $entries = array_map(fn (FinanceEntry $entry): array => $this->entry($presenter->entry($entry), $references[$entry->uuid] ?? [], $timezone), $ledger);
            }
        } catch (FinanceFailed|AuthorizationException $failure) {
            $this->error = Refusals::text($failure->getMessage());
        } catch (EntitlementRequired $missing) {
            $this->error = self::entitlementRefusal($missing);
        } catch (NotFoundHttpException) {
            $this->error = (string) __('That branch could not be found.');
        }

        return view('livewire.center.finance-overview', [
            'tabs' => MoneyTabs::for($user, 'finance'),
            'window' => $this->windowControl($timezone),
            'branches' => array_map(static fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name->get()], $branches),
            'summary' => $summary,
            'entries' => $entries,
            'owned' => $owned,
            'offer' => $owned ? null : $this->lockedFeature('finance'),
            'reports' => $this->reportLinks($entitlements),
            'ledgerCapped' => count($entries) >= FinanceQuery::MAX_LIST,
            'shiftsLink' => Route::has('center.shifts') ? route('center.shifts', $this->branch === '' ? [] : ['branch' => $this->branch]) : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary  FinanceDashboard::summary()
     * @return array<string, mixed>
     */
    private function present(array $summary, string $timezone): array
    {
        $money = static fn (int $minor): Money => Money::fromMinor($minor, Currency::default());
        $methods = static function (array $byMethod) use ($money): array {
            $rows = [];

            foreach ($byMethod as $method => $minor) {
                $rows[] = ['label' => MoneyLabels::method((string) $method), 'amount' => $money((int) $minor)->formatted(), 'zero' => (int) $minor === 0];
            }

            return $rows;
        };

        $net = $money($summary['net_movement_minor']);
        $variance = $money($summary['variance_total_minor']);

        return [
            'invoiced' => $money($summary['invoiced_minor'])->formatted(),
            'invoice_count' => $summary['invoice_count'],
            'voided' => $money($summary['voided_minor'])->formatted(),
            'has_voided' => $summary['voided_minor'] > 0,
            'collected' => $money($summary['collected_minor'])->formatted(),
            'collected_by_method' => $methods($summary['collected_by_method']),
            'refunded' => $money($summary['refunded_minor'])->formatted(),
            'refunded_by_method' => $methods($summary['refunded_by_method']),
            'expenses' => $money($summary['net_expenses_minor'])->formatted(),
            'expense_reversals' => $summary['expense_reversals_minor'] > 0 ? $money($summary['expense_reversals_minor'])->formatted() : null,
            'net' => $net->formatted(),
            'net_tone' => $net->isNegative() ? 'danger' : null,
            'outstanding' => $money($summary['outstanding_minor'])->formatted(),
            'outstanding_tone' => $summary['outstanding_minor'] > 0 ? 'warning' : null,
            'variance' => $variance->formatted(),
            'variance_tone' => $variance->isZero() ? null : ($variance->isNegative() ? 'danger' : 'warning'),
            'by_branch' => count($summary['by_branch']) > 1 ? array_map(static fn (array $row): array => [
                'branch' => $row['branch'],
                'name' => $row['name'],
                'invoiced' => $money($row['invoiced_minor'])->formatted(),
                'collected' => $money($row['collected_minor'])->formatted(),
                'refunded' => $money($row['refunded_minor'])->formatted(),
                'expenses' => $money($row['net_expenses_minor'])->formatted(),
            ], $summary['by_branch']) : [],
            'variances' => array_map(static function (array $row) use ($money, $timezone): array {
                $variance = $money($row['variance_minor']);

                return [
                    'shift' => $row['shift'],
                    'cashier' => $row['cashier'] ?? '—',
                    'when' => BranchTime::label($row['reconciled_at'], $timezone),
                    'expected' => $money($row['expected_minor'])->formatted(),
                    'counted' => $money($row['counted_minor'])->formatted(),
                    'variance' => $variance->formatted(),
                    'tone' => $variance->isZero() ? 'success' : ($variance->isNegative() ? 'danger' : 'warning'),
                ];
            }, $summary['variances']),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry  FinancePresenter::entry()
     * @param  array{reference?: string|null, detail?: string|null}  $reference
     * @return array<string, mixed>
     */
    private function entry(array $entry, array $reference, string $timezone): array
    {
        return $entry + [
            'kind_label' => (string) __('manager_finance.entry_kind.'.$entry['kind']),
            'reference' => $reference['reference'] ?? null,
            'detail' => $reference['detail'] ?? null,
            'method_label' => MoneyLabels::method($entry['method']),
            'when' => BranchTime::label($entry['occurred_at'], $timezone),
            'incoming' => $entry['direction'] === 'in',
        ];
    }

    /**
     * The Standard Reports that carry these figures further, with CSV — linked
     * rather than rebuilt here (docs/28-REPORTS.md).
     *
     * @return list<array{label: string, href: string}>
     */
    private function reportLinks(Entitlements $entitlements): array
    {
        if (! $this->user()->hasPermission(Permission::ReportView) || ! $entitlements->enabled('reports_standard') || ! Route::has('center.reports')) {
            return [];
        }

        return [
            ['label' => (string) __('manager_finance.overview.report_payments'), 'href' => route('center.reports', ['report' => 'sales_payments'])],
            ['label' => (string) __('manager_finance.overview.report_movements'), 'href' => route('center.reports', ['report' => 'finance_movements'])],
        ];
    }
}
