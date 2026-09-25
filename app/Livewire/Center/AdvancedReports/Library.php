<?php

declare(strict_types=1);

namespace App\Livewire\Center\AdvancedReports;

use App\Kernel\Authorization\Permission;
use App\Modules\AdvancedReports\Application\AdvancedReportCatalog;
use App\Modules\AdvancedReports\Application\AdvancedReports as AdvancedReportRunner;
use App\View\AdvancedReports\LibraryPresenter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

/**
 * The report library: the ten catalog reports — the same results the API,
 * the CSV export and RAYAN use — with their comparison against the page's
 * comparison period, charts, a typed detail table, CSV and print.
 */
#[Lazy]
final class Library extends Component
{
    use AdvancedScope;

    #[Reactive]
    public string $report = '';

    #[Reactive]
    public string $range = 'this_month';

    #[Reactive]
    public ?string $from = null;

    #[Reactive]
    public ?string $to = null;

    #[Reactive]
    public string $comparison = 'previous';

    #[Reactive]
    public string $branch = '';

    #[Reactive]
    public int $version = 0;

    public function placeholder(): View
    {
        return view('livewire.center.advanced-reports.placeholder', ['kind' => 'library']);
    }

    public function render(AdvancedReportCatalog $catalog, AdvancedReportRunner $reports): View
    {
        $state = ['result' => null, 'error' => null, 'state' => 'ready', 'exportUrl' => null];

        if (! $this->mayRead()) {
            return view('livewire.center.advanced-reports.library', ['state' => 'forbidden'] + $state);
        }

        $definition = $catalog->find($this->report);

        if ($definition === null) {
            return view('livewire.center.advanced-reports.library', ['state' => $this->report === '' ? 'none' : 'unknown'] + $state);
        }

        foreach ($definition['permissions'] as $code) {
            $permission = Permission::tryFrom($code);

            if (! $permission instanceof Permission || ! $this->viewer()->hasPermission($permission)) {
                return view('livewire.center.advanced-reports.library', ['state' => 'report_forbidden'] + $state);
            }
        }

        try {
            $period = $this->advancedPeriod();
            [$current, $comparison] = $this->reportRequests($period);
            $result = (new LibraryPresenter(app()->getLocale()))->present($reports->run($this->report, $this->viewer(), $current, $comparison)->toArray());
            $branch = $this->branchUuid();

            return view('livewire.center.advanced-reports.library', [
                'result' => $result,
                'exportUrl' => $this->viewer()->hasPermission(Permission::ReportExport) ? route('center.advanced-reports.export', array_filter([
                    'report' => $this->report,
                    'from' => $period->from->toDateString(),
                    'to' => $period->to->toDateString(),
                    'compare_from' => $period->compareFrom->toDateString(),
                    'compare_to' => $period->compareTo->toDateString(),
                    'branch' => $branch,
                ])) : null,
            ] + $state);
        } catch (Throwable $failure) {
            return view('livewire.center.advanced-reports.library', ['error' => $this->readFailure($failure)] + $state);
        }
    }
}
