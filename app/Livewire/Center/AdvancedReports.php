<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\AdvancedReports\AdvancedScope;
use App\Livewire\Center\AdvancedReports\AiInsights;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Dashboard\BranchOptions;
use App\Modules\AdvancedReports\Application\AdvancedReportCatalog;
use App\View\AdvancedReports\AdvancedPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Advanced Reports: the paid analytics workspace.
 *
 * This component is the page shell — the gates (report permission, then the
 * `reports_advanced` entitlement: a locked center gets the upgrade page and
 * no data is read), the one toolbar every section follows (period presets,
 * comparison mode, branch) and the view switch. The sections are their own
 * lazy components (AdvancedReports\Workspace, Compare, Library, AiInsights)
 * that receive the toolbar state as reactive props, so changing a filter
 * re-renders the figures in place while an AI question or a comparison pick
 * re-renders only its own section. Every read runs on the reporting target.
 */
#[Layout('components.layouts.app')]
final class AdvancedReports extends Component
{
    use AdvancedScope;
    use RequiresFeature;

    /** The AI panel keeps the API's `report-analysis` limit (AiInsights). */
    public const ASKS_PER_MINUTE = AiInsights::ASKS_PER_MINUTE;

    public const VIEWS = ['insights', 'compare', 'reports'];

    /** The catalog report the Reports view opens (route segment). */
    public string $report = '';

    #[Url(as: 'view', history: true)]
    public string $view = 'insights';

    #[Url(as: 'range', history: true)]
    public string $range = 'this_month';

    #[Url(as: 'from', history: true)]
    public ?string $from = null;

    #[Url(as: 'to', history: true)]
    public ?string $to = null;

    #[Url(as: 'compare', history: true)]
    public string $comparison = 'previous';

    #[Url(as: 'branch', history: true)]
    public string $branch = '';

    public ?string $customFrom = null;

    public ?string $customTo = null;

    public bool $customOpen = false;

    /** Bumped by "Refresh": every section re-reads. */
    public int $version = 0;

    public function mount(?string $report = null): void
    {
        $this->report = (string) $report;

        if ($this->report !== '') {
            $this->view = 'reports';
        }

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'insights';
        }
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, self::VIEWS, true) ? $view : 'insights';
    }

    public function setRange(string $preset): void
    {
        if ($preset === 'custom') {
            $period = $this->advancedPeriod();
            $this->customFrom ??= $period->from->toDateString();
            $this->customTo ??= $period->to->toDateString();
            $this->customOpen = true;

            return;
        }

        if (! in_array($preset, AdvancedPeriod::PRESETS, true)) {
            return;
        }

        $this->range = $preset;
        $this->from = null;
        $this->to = null;
        $this->customOpen = false;
        $this->resetErrorBag(['customFrom', 'customTo']);
    }

    public function applyCustomRange(): void
    {
        $today = CarbonImmutable::now(app(BranchOptions::class)->timezone($this->viewer(), $this->branchUuid()))->toDateString();

        $this->validate([
            'customFrom' => ['required', 'date_format:Y-m-d', 'before_or_equal:customTo', 'before_or_equal:'.$today],
            'customTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:customFrom', 'before_or_equal:'.$today],
        ], [], [
            'customFrom' => __('ui.fields.from'),
            'customTo' => __('ui.fields.to'),
        ]);

        $days = (int) CarbonImmutable::parse((string) $this->customFrom)->diffInDays(CarbonImmutable::parse((string) $this->customTo)) + 1;

        if ($days > AdvancedPeriod::MAX_DAYS) {
            $this->addError('customFrom', __('manager_advanced.errors.invalid_range'));

            return;
        }

        $this->range = 'custom';
        $this->from = $this->customFrom;
        $this->to = $this->customTo;
        $this->customOpen = false;
    }

    public function cancelCustomRange(): void
    {
        $this->customOpen = false;
        $this->resetErrorBag(['customFrom', 'customTo']);
    }

    public function setComparison(string $mode): void
    {
        $this->comparison = in_array($mode, AdvancedPeriod::COMPARISONS, true) ? $mode : 'previous';
    }

    public function resetFilters(): void
    {
        $this->reset('range', 'from', 'to', 'comparison', 'branch', 'customFrom', 'customTo', 'customOpen');
    }

    public function refreshData(): void
    {
        $this->version++;
    }

    public function render(BranchOptions $branches, AdvancedReportCatalog $catalog): View
    {
        $user = $this->viewer();
        $shell = ['standardUrl' => route('center.reports')];

        if (! $user->hasPermission(Permission::ReportView)) {
            return view('livewire.center.advanced-reports', ['state' => 'forbidden'] + $shell)->title(__('manager_advanced.page.title'));
        }

        if ($offer = $this->lockedFeature('reports_advanced')) {
            // The upgrade state: no report data is loaded while it is locked.
            return view('livewire.center.advanced-reports', ['state' => 'locked', 'offer' => $offer] + $shell)->title(__('manager_advanced.page.title'));
        }

        $locale = app()->getLocale();
        $period = $this->advancedPeriod();
        $branch = $this->branchUuid();
        $options = $branches->for($user);
        $permitted = $this->permittedReports($catalog);
        $scope = ['range' => $period->preset, 'from' => $this->from, 'to' => $this->to, 'comparison' => $period->comparison, 'branch' => (string) $branch, 'version' => $this->version];
        $query = array_filter(['range' => $this->range, 'from' => $this->from, 'to' => $this->to, 'compare' => $this->comparison === 'previous' ? null : $this->comparison, 'branch' => $branch]);
        $csv = $user->hasPermission(Permission::ReportExport);

        return view('livewire.center.advanced-reports', [
            'state' => 'ready',
            'period' => $period,
            'periodLabel' => $period->label($locale),
            'today' => CarbonImmutable::now($period->timezone)->toDateString(),
            'comparisonLabel' => $period->comparisonLabel($locale),
            'presets' => AdvancedPeriod::PRESETS,
            'branchOptions' => count($options) > 1 ? $options : [],
            'branchName' => collect($options)->firstWhere('uuid', $branch)['name'] ?? null,
            'filtered' => $branch !== null || $period->preset !== 'this_month' || $period->comparison !== 'previous',
            // What the phone's folded filter toggle is hiding.
            'activeFilters' => (int) ($branch !== null) + (int) ($period->comparison !== 'previous'),
            'scope' => $scope,
            'reportCode' => $this->report !== '' ? $this->report : (string) array_key_first($permitted),
            'aiReport' => isset($permitted['period_comparison']) ? 'period_comparison' : (string) array_key_first($permitted),
            'hasReports' => $permitted !== [],
            'exports' => $csv ? array_map(fn (string $code): array => [
                'label' => __('manager_advanced.reports.'.$code),
                'href' => route('center.advanced-reports.export', array_filter([
                    'report' => $code,
                    'from' => $period->from->toDateString(),
                    'to' => $period->to->toDateString(),
                    'compare_from' => $period->compareFrom->toDateString(),
                    'compare_to' => $period->compareTo->toDateString(),
                    'branch' => $branch,
                ])),
            ], array_keys($permitted)) : [],
            'reportTabs' => array_map(static fn (string $code): array => [
                'code' => $code,
                'href' => route('center.advanced-reports', ['report' => $code, 'view' => 'reports'] + $query),
            ], array_keys($permitted)),
        ] + $shell)->title(__('manager_advanced.page.title'));
    }

    /**
     * The catalog reports whose source data this viewer may read.
     *
     * @return array<string, array{title: string, description: string, permissions: list<string>}>
     */
    private function permittedReports(AdvancedReportCatalog $catalog): array
    {
        return array_filter($catalog->all(), function (array $definition): bool {
            foreach ($definition['permissions'] as $code) {
                $permission = Permission::tryFrom($code);

                if (! $permission instanceof Permission || ! $this->viewer()->hasPermission($permission)) {
                    return false;
                }
            }

            return true;
        });
    }
}
