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
use App\Modules\Finance\Application\Actions\CloseShiftWithCount;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Finance\Domain\Models\ShiftReconciliation;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Application\SalesQuery;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Models\CashierShift;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cashier shifts and their drawer counts, per branch.
 *
 * Every shift still open, and those opened in the window: who, since when,
 * what they issued, and — once closed with Finance — the counted drawer against
 * what it should have held. A supervisor closes someone else's shift from
 * here (with `cashier_shift.supervise`); a cashier sees and closes only their
 * own. The count is BLIND: the expected figure is never shown before the
 * drawer is counted (docs/20-FINANCE.md §33).
 */
#[Layout('components.layouts.app')]
final class CashierShifts extends Component
{
    use GuardsMoneyActions;
    use HasMoneyWindow;
    use RequiresFeature;

    #[Url]
    public string $branch = '';

    /** The open shift being closed, or ''. */
    public string $closing = '';

    public string $countedCash = '';

    public string $closeNote = '';

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

    public function startClose(string $uuid): void
    {
        $this->closing = $uuid;
        $this->reset(['countedCash', 'closeNote', 'error', 'saved']);
    }

    public function cancelClose(): void
    {
        $this->reset(['closing', 'countedCash', 'closeNote']);
    }

    public function close(FinanceQuery $finance, ManageCashierShift $shifts, CloseShiftWithCount $count, Entitlements $entitlements): void
    {
        $this->attempt(function () use ($finance, $shifts, $count, $entitlements): void {
            // Own shift or — with supervise — anyone's at a branch you may
            // work in; the Actions check the same again.
            $shift = $finance->shift($this->closing, $this->user());
            $note = trim($this->closeNote) === '' ? null : $this->closeNote;

            if (! $entitlements->enabled('finance')) {
                $shifts->close($shift, $this->user(), $note);
                $this->saved = (string) __('Shift closed.');
            } else {
                if (trim($this->countedCash) === '') {
                    throw SaleFailed::policy('Count the cash in the drawer before closing the shift.');
                }

                $result = $count($shift, $this->user(), $this->counted(), $note);
                $currency = Currency::tryFrom($result->currency) ?? Currency::default();

                $this->saved = (string) __('Shift closed. Expected :expected, counted :counted, difference :variance.', [
                    'expected' => Money::fromMinor($result->expected_cash_minor, $currency)->formatted(),
                    'counted' => Money::fromMinor($result->counted_cash_minor, $currency)->formatted(),
                    'variance' => Money::fromMinor($result->variance_minor, $currency)->formatted(),
                ]);
            }

            $this->reset(['closing', 'countedCash', 'closeNote']);
        });
    }

    protected function defaultRange(): string
    {
        return 'last_7_days';
    }

    protected function windowTimezone(): string
    {
        return BranchTime::zoneOf(Branch::query()->where('uuid', $this->branch)->first());
    }

    public function render(SalesQuery $query, FinanceQuery $finance, Entitlements $entitlements): View
    {
        $hasPos = $entitlements->enabled('pos');

        if (! $hasPos && ! $query->hasHistory() && ($locked = $this->lockedView('pos'))) {
            return $locked;
        }

        $user = $this->user();
        $manages = $user->hasPermission(Permission::CashierShiftManage);
        $supervises = $user->hasPermission(Permission::CashierShiftSupervise);

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

        $open = [];
        $closed = [];
        $listError = '';

        try {
            if (($manages || $supervises) && $branch instanceof Branch) {
                $shifts = $query->shifts($user, $branch->uuid, $from, $until);
                $summaries = $query->shiftSummaries($shifts);
                $counts = $finance->reconciliationsFor($shifts);

                foreach ($shifts as $shift) {
                    $row = $this->row($shift, $summaries[(int) $shift->getKey()] ?? null, $counts[(int) $shift->getKey()] ?? null, $timezone, $user->getKey(), $supervises, $hasPos);

                    if ($shift->isOpen()) {
                        $open[] = $row;
                    } else {
                        $closed[] = $row;
                    }
                }
            }
        } catch (AuthorizationException|SaleFailed $failure) {
            $listError = Refusals::text($failure->getMessage());
        } catch (NotFoundHttpException) {
            $listError = (string) __('That branch could not be found.');
        }

        $closingRow = null;

        foreach ($open as $row) {
            if ($row['uuid'] === $this->closing) {
                $closingRow = $row;
            }
        }

        return view('livewire.center.shifts', [
            'tabs' => MoneyTabs::for($user, 'shifts'),
            'window' => $this->windowControl($timezone),
            'branches' => array_map(static fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name->get()], $branches),
            'open' => $open,
            'closed' => $closed,
            'closingRow' => $closingRow,
            'listError' => $listError,
            'denied' => ! $manages && ! $supervises,
            'supervises' => $supervises,
            'countsDrawer' => $entitlements->enabled('finance'),
            'hasPos' => $hasPos,
            'offer' => $hasPos ? null : $this->lockedFeature('pos'),
        ])->title(__('manager_finance.shifts.title'));
    }

    /**
     * @param  array{sales: int, voided: int, totals: list<array{currency: string, grand_total_minor: int}>}|null  $summary
     * @return array<string, mixed>
     */
    private function row(CashierShift $shift, ?array $summary, ?ShiftReconciliation $count, string $timezone, mixed $viewerId, bool $supervises, bool $hasPos): array
    {
        $currency = ($count === null ? null : Currency::tryFrom($count->currency)) ?? Currency::default();
        $variance = $count === null ? null : Money::fromMinor($count->variance_minor, $currency);

        return [
            'uuid' => $shift->uuid,
            'cashier' => $shift->user->name,
            'mine' => $shift->user_id === $viewerId,
            'opened_at' => BranchTime::label($shift->opened_at->toIso8601String(), $timezone),
            'closed_at' => $shift->closed_at === null ? null : BranchTime::label($shift->closed_at->toIso8601String(), $timezone),
            // Named only when somebody else — a supervisor — closed it.
            'closed_by' => $shift->closed_by_label !== null && $shift->closed_by_label !== $shift->user->name ? $shift->closed_by_label : null,
            'opening_cash' => $shift->opening_cash_minor === null ? null : Money::fromMinor($shift->opening_cash_minor, Currency::default())->formatted(),
            'note' => $shift->closing_note ?? $shift->opening_note,
            'sales' => $summary['sales'] ?? 0,
            'voided' => $summary['voided'] ?? 0,
            'totals' => TillView::shiftTotals($summary),
            'counted' => $count === null ? null : [
                'expected' => Money::fromMinor($count->expected_cash_minor, $currency)->formatted(),
                'counted' => Money::fromMinor($count->counted_cash_minor, $currency)->formatted(),
                'variance' => $variance?->formatted(),
                'tone' => $variance === null || $variance->isZero() ? 'success' : ($variance->isNegative() ? 'danger' : 'warning'),
                'label' => (string) __($variance === null || $variance->isZero() ? 'manager_finance.shifts.balanced' : ($variance->isNegative() ? 'manager_finance.shifts.short' : 'manager_finance.shifts.over')),
                'by' => $count->reconciled_by_label,
            ],
            'can_close' => $hasPos && $shift->isOpen() && ($shift->user_id === $viewerId || $supervises),
        ];
    }

    /**
     * The typed count, in the center's currency — a parse, never a float.
     *
     * @throws SaleFailed
     */
    private function counted(): int
    {
        try {
            $minor = Money::fromMajorString($this->countedCash, Currency::default())->minor;
        } catch (InvalidArgumentException) {
            throw SaleFailed::policy('Enter an amount like 25000.');
        }

        if ($minor < 0) {
            throw SaleFailed::policy('Enter an amount like 25000.');
        }

        return $minor;
    }
}
