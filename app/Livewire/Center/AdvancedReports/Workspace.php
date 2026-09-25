<?php

declare(strict_types=1);

namespace App\Livewire\Center\AdvancedReports;

use App\Kernel\Authorization\Permission;
use App\Modules\AdvancedReports\Application\AdvancedWorkspace;
use App\Modules\Catalog\Application\DashboardServiceOptions;
use App\Modules\Employees\Application\DashboardTeamSnapshot;
use App\View\AdvancedReports\WorkspacePresenter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

/**
 * The insights view: executive summary, strongest movements, business trends
 * and the intelligence sections, all from ONE AdvancedWorkspace read (every
 * reader once per period, reporting target only). Lazy: the page paints its
 * toolbar at once and this section shows chart skeletons until it arrives; a
 * filter change afterwards re-renders it in place (dimmed), never a skeleton.
 *
 * `focus` narrows the whole view to one employee's or one service's work
 * (`employee:<uuid>` / `service:<uuid>`), checked against the viewer's own
 * options on every render; the readers resolve the uuid in branch-scoped SQL.
 */
#[Lazy]
final class Workspace extends Component
{
    use AdvancedScope;

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

    /** '' (all work), `employee:<uuid>` or `service:<uuid>`. */
    public string $focus = '';

    public function placeholder(): View
    {
        return view('livewire.center.advanced-reports.placeholder', ['kind' => 'workspace']);
    }

    public function clearFocus(): void
    {
        $this->focus = '';
    }

    public function render(AdvancedWorkspace $workspace): View
    {
        if (! $this->mayRead()) {
            return view('livewire.center.advanced-reports.workspace', ['data' => null, 'error' => __('manager_advanced.errors.forbidden'), 'focusOptions' => [], 'focusName' => null]);
        }

        $options = $this->focusOptions();
        [$kind, $uuid] = $this->resolvedFocus($options);
        $shared = ['focusOptions' => $options, 'focusName' => $kind === null ? null : $options[$kind][$uuid]];

        try {
            $period = $this->advancedPeriod();
            [$current, $comparison] = $this->reportRequests($period);
            $data = (new WorkspacePresenter($period, app()->getLocale()))->present($workspace->build($this->viewer(), $current, $comparison, $kind === null ? [] : [$kind => $uuid]));

            return view('livewire.center.advanced-reports.workspace', ['data' => $data, 'error' => null] + $shared);
        } catch (Throwable $failure) {
            return view('livewire.center.advanced-reports.workspace', ['data' => null, 'error' => $this->readFailure($failure)] + $shared);
        }
    }

    /**
     * What the view may be focused on: employees and services, for a viewer
     * who may read the visits that record who performed what.
     *
     * @return array{employee?: array<string, string>, service?: array<string, string>}
     */
    private function focusOptions(): array
    {
        if (! $this->viewer()->hasPermission(Permission::JourneyView)) {
            return [];
        }

        $employees = [];
        foreach (app(DashboardTeamSnapshot::class)->options($this->viewer()) as $row) {
            $employees[(string) $row['uuid']] = (string) $row['name'];
        }

        $services = [];
        foreach (app(DashboardServiceOptions::class)->all() as $row) {
            $services[(string) $row['uuid']] = $row['archived'] ? (string) __('manager_advanced.compare.archived', ['name' => $row['name']]) : (string) $row['name'];
        }

        return array_filter(['employee' => $employees, 'service' => $services]);
    }

    /**
     * The focus as [kind, uuid], or [null, null] when it is empty or not one
     * of this viewer's options (a stale or hand-made value is dropped).
     *
     * @param  array{employee?: array<string, string>, service?: array<string, string>}  $options
     * @return array{0: 'employee'|'service'|null, 1: string|null}
     */
    private function resolvedFocus(array $options): array
    {
        [$kind, $uuid] = array_pad(explode(':', $this->focus, 2), 2, '');

        if (($kind === 'employee' || $kind === 'service') && isset($options[$kind][$uuid])) {
            return [$kind, $uuid];
        }

        $this->focus = '';

        return [null, null];
    }
}
