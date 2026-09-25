<?php

declare(strict_types=1);

namespace App\Livewire\Center\AdvancedReports;

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\Dashboard\BranchOptions;
use App\Modules\AdvancedReports\Application\AdvancedComparison;
use App\Modules\AdvancedReports\Application\AdvancedWorkspace;
use App\Modules\Catalog\Application\DashboardServiceOptions;
use App\Modules\Employees\Application\DashboardTeamSnapshot;
use App\View\AdvancedReports\ComparePresenter;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

/**
 * Comparison mode: Branch vs Branch, Employee vs Employee, Service vs Service
 * (2–4 picked entities over the selected period) and Current vs Comparison
 * period. The picks are this section's own state, so choosing an entity
 * re-reads only the comparison. Every pick is checked against the viewer's
 * own options; the readers then resolve it inside their branch-scoped SQL.
 */
#[Lazy]
final class Compare extends Component
{
    use AdvancedScope;

    public const MODES = ['periods', 'branches', 'employees', 'services'];

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

    public string $mode = 'periods';

    /** @var list<string> */
    public array $selected = [];

    public function placeholder(): View
    {
        return view('livewire.center.advanced-reports.placeholder', ['kind' => 'compare']);
    }

    public function setMode(string $mode): void
    {
        $this->mode = in_array($mode, self::MODES, true) ? $mode : 'periods';
        $this->selected = [];
    }

    public function toggle(string $key): void
    {
        if (in_array($key, $this->selected, true)) {
            $this->selected = array_values(array_diff($this->selected, [$key]));

            return;
        }

        if (count($this->selected) < AdvancedComparison::MAX_ENTITIES && array_key_exists($key, $this->options())) {
            $this->selected[] = $key;
        }
    }

    public function render(AdvancedComparison $comparison, AdvancedWorkspace $workspace): View
    {
        if (! $this->mayRead()) {
            return view('livewire.center.advanced-reports.compare', ['error' => __('manager_advanced.errors.forbidden'), 'data' => null, 'options' => [], 'modes' => [], 'max' => AdvancedComparison::MAX_ENTITIES]);
        }

        $modes = $this->modes();
        $this->mode = in_array($this->mode, $modes, true) ? $this->mode : 'periods';
        $options = $this->mode === 'periods' ? [] : $this->options();
        $this->selected = array_values(array_filter($this->selected, static fn (string $key): bool => array_key_exists($key, $options)));

        if ($this->selected === [] && $options !== []) {
            $this->selected = array_slice(array_keys($options), 0, 2);
        }

        try {
            $period = $this->advancedPeriod();
            [$current, $previous] = $this->reportRequests($period, $this->mode === 'branches');
            $presenter = new ComparePresenter($period, app()->getLocale());
            $data = $this->mode === 'periods'
                ? $presenter->periods($workspace->build($this->viewer(), $current, $previous))
                : $presenter->entities($this->mode, $comparison->compare($this->mode, $this->viewer(), $current, $this->selected)['entities'], $options);

            return view('livewire.center.advanced-reports.compare', [
                'error' => null,
                'data' => $data + ['period' => $period->label(app()->getLocale()), 'comparison' => $period->comparisonLabel(app()->getLocale())],
                'options' => $options,
                'modes' => $modes,
                'max' => AdvancedComparison::MAX_ENTITIES,
            ]);
        } catch (Throwable $failure) {
            return view('livewire.center.advanced-reports.compare', ['error' => $this->readFailure($failure), 'data' => null, 'options' => $options, 'modes' => $modes, 'max' => AdvancedComparison::MAX_ENTITIES]);
        }
    }

    /**
     * The comparisons this viewer can make: branches only with two or more
     * branches in scope; employees and services need visit data access.
     *
     * @return list<string>
     */
    private function modes(): array
    {
        $modes = ['periods'];

        if (count(app(BranchOptions::class)->for($this->viewer())) > 1) {
            $modes[] = 'branches';
        }

        if ($this->viewer()->hasPermission(Permission::JourneyView)) {
            $modes[] = 'employees';
            $modes[] = 'services';
        }

        return $modes;
    }

    /**
     * uuid => name of what may be picked in the current mode.
     *
     * @return array<string, string>
     */
    private function options(): array
    {
        $rows = match ($this->mode) {
            'branches' => array_map(static fn (array $branch): array => ['uuid' => $branch['uuid'], 'name' => $branch['name']], app(BranchOptions::class)->for($this->viewer())),
            'employees' => app(DashboardTeamSnapshot::class)->options($this->viewer()),
            'services' => array_map(static fn (array $service): array => ['uuid' => $service['uuid'], 'name' => $service['archived'] ? __('manager_advanced.compare.archived', ['name' => $service['name']]) : $service['name']], app(DashboardServiceOptions::class)->all()),
            default => [],
        };

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['uuid']] = (string) $row['name'];
        }

        return $options;
    }
}
