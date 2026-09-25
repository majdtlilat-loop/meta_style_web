<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\DateRange;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Dashboard\BranchOptions;
use App\Livewire\Center\Reports\Concerns\HasReportPeriod;
use App\Livewire\Center\Reports\ReportFilters;
use App\Livewire\Center\Reports\ReportsData;
use App\Modules\Reports\Application\ReportPeriod;
use App\Modules\Reports\Application\StandardReportCatalog;
use App\View\Manager\FeatureOffer;
use App\View\Reports\StandardReportView;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Standard Reports: one analytics view per report, over a shared period and
 * its like-for-like comparison, narrowed by branch (never widened) and by
 * whatever else the chosen report's readers support.
 *
 * Three different "no"s, never one: the center does not have the product
 * (the upgrade state, no data loaded), the viewer may not open reports at
 * all, or the viewer may not see the source data of THIS report — the other
 * reports stay available. The figures come from the Reports module, which
 * checks all of it again on the server; this component only orchestrates.
 */
#[Layout('components.layouts.app')]
final class Reports extends Component
{
    use HasReportPeriod;
    use RequiresFeature;

    /** The order a manager reads the reports in (the catalog keeps its own). */
    public const ORDER = [
        'business_overview' => 'dashboard',
        'sales_payments' => 'sales',
        'booking_activity' => 'calendar',
        'visit_service_delivery' => 'scissors',
        'employee_delivery' => 'staff',
        'customer_activity' => 'customers',
        'benefit_usage' => 'loyalty',
        'review_summary' => 'reviews',
        'queue_operations' => 'queue',
        'finance_movements' => 'finance',
    ];

    /** Narrowing the page can offer; each report keeps only what it supports. */
    private const FILTERS = ['source', 'status', 'employee', 'service', 'category'];

    /** From the route; a tab is a new page, never a client-side change. */
    #[Locked]
    public string $report = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $employee = '';

    #[Url]
    public string $service = '';

    #[Url]
    public string $category = '';

    public function mount(?string $report = null): void
    {
        $this->report = (string) $report;
    }

    protected function rangeTimezone(): string
    {
        return app(BranchOptions::class)->timezone($this->user(), $this->branch === '' ? null : $this->branch);
    }

    public function resetFilters(): void
    {
        $this->reset('branch', 'source', 'status', 'employee', 'service', 'category');
    }

    /** The name the shared filter bar has always called. */
    public function clearFilters(): void
    {
        $this->resetFilters();
    }

    /** Drops this view's cached figures; the render reads them afresh. */
    public function refresh(ReportsData $data, BranchOptions $branches, ReportFilters $filters, StandardReportCatalog $catalog): void
    {
        $user = $this->user();
        $code = $this->code();
        $supported = $catalog->find($code)['filters'] ?? [];

        $data->forget($code, $user, $this->period(), $branches->valid($user, $this->branch), $this->values($filters, $user, $supported), app()->getLocale());
    }

    public function render(StandardReportCatalog $catalog, BranchOptions $branches, ReportFilters $filters, ReportsData $data, StandardReportView $view): View
    {
        $user = $this->user();

        if (! $user->hasPermission(Permission::ReportView)) {
            return view('livewire.center.reports', ['state' => 'forbidden'] + $this->shell())->title(__('manager_reports.page.title'));
        }

        if ($locked = $this->lockedView('reports_standard')) {
            return $locked;
        }

        $locale = app()->getLocale();
        $permitted = $this->permitted($catalog, $user);
        $code = $this->code($permitted);
        $definition = $catalog->find($code);
        $period = $this->period();
        $branch = $branches->valid($user, $this->branch);
        $supported = $definition['filters'] ?? [];
        $values = $this->values($filters, $user, $supported);

        $state = match (true) {
            $permitted === [] => 'no_reports',
            $definition === null => 'unknown',
            ! in_array($code, $permitted, true) => 'report_forbidden',
            default => 'ready',
        };

        $result = null;
        $error = null;

        if ($state === 'ready') {
            try {
                $analytics = $data->get($code, $user, $period, $branch, $values, $locale);
                $result = $view->present($analytics, $period, $locale, fn (string $uuid): ?string => $user->hasPermission(Permission::CustomerView) ? route('center.customers.show', ['uuid' => $uuid]) : null);
            } catch (AuthorizationException) {
                $state = 'report_forbidden';
            } catch (EntitlementRequired) {
                $error = __('manager_reports.errors.unavailable');
            } catch (InvalidArgumentException) {
                $error = __('manager_reports.errors.invalid_range');
            } catch (Throwable $failure) {
                report($failure);
                $error = __('manager_reports.errors.failed');
            }
        }

        $query = array_filter(['range' => $this->range, 'from' => $this->from, 'to' => $this->to, 'branch' => $branch]);
        $applied = array_filter($values);

        return view('livewire.center.reports', [
            'state' => $state,
            'code' => $code,
            'title' => $definition !== null ? __('manager_reports.standard.'.$code.'.title') : '',
            'tabs' => array_map(fn (string $key): array => [
                'code' => $key,
                'label' => __('manager_reports.std.tabs.'.$key),
                'icon' => self::ORDER[$key],
                'href' => route('center.reports', ['report' => $key] + $query),
                'active' => $key === $code,
            ], $permitted),
            'fallback' => $permitted !== [] ? [
                'label' => __('manager_reports.standard.'.$permitted[0].'.title'),
                'href' => route('center.reports', ['report' => $permitted[0]] + $query),
            ] : null,
            'toolbar' => $this->toolbar($period, $locale),
            'selects' => $state === 'ready' ? $this->selects($branches, $filters, $user, $supported) : [],
            'filtered' => $branch !== null || $applied !== [],
            'activeFilters' => ($branch !== null ? 1 : 0) + count($applied),
            'result' => $result,
            'error' => $error,
            'exportUrl' => $result !== null && $user->hasPermission(Permission::ReportExport) ? route('center.reports.export', array_filter([
                'report' => $code,
                'from' => $period->current->from->toDateString(),
                'to' => $period->current->to->toDateString(),
                'branch' => $branch,
            ] + $applied)) : null,
        ] + $this->shell())->title($state === 'ready' || $state === 'report_forbidden'
            ? __('manager_reports.std.tabs.'.$code).' · '.__('manager_reports.page.title')
            : __('manager_reports.page.title'));
    }

    /**
     * The reports this viewer may open, in reading order.
     *
     * @return list<string>
     */
    private function permitted(StandardReportCatalog $catalog, User $user): array
    {
        $codes = [];

        foreach (array_keys(self::ORDER) as $code) {
            $definition = $catalog->find($code);

            if ($definition !== null && $this->holdsAll($user, $definition['permissions'])) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /** @param list<string>|null $permitted */
    private function code(?array $permitted = null): string
    {
        if ($this->report !== '') {
            return $this->report;
        }

        $permitted ??= $this->permitted(app(StandardReportCatalog::class), $this->user());

        return $permitted[0] ?? '';
    }

    /**
     * The URL's values for the filters this report supports, each checked
     * against the real options (a stale or foreign value is dropped).
     *
     * @param  list<string>  $supported
     * @return array<string, string|null>
     */
    private function values(ReportFilters $filters, User $user, array $supported): array
    {
        $values = [];

        foreach (array_intersect(self::FILTERS, $supported) as $filter) {
            $values[$filter] = match ($filter) {
                'source' => $filters->source($this->source),
                'status' => $filters->status($this->status),
                'employee' => $filters->employee($user, $this->employee),
                'service' => $filters->service($this->service),
                default => $filters->category($this->category),
            };
        }

        return $values;
    }

    /**
     * The branch picker (more than one branch in scope) and the filters the
     * current report supports.
     *
     * @param  list<string>  $supported
     * @return list<array{name: string, label: string, all: string, options: list<array{value: string, label: string}>}>
     */
    private function selects(BranchOptions $branches, ReportFilters $filters, User $user, array $supported): array
    {
        $selects = [];
        $branchOptions = $branches->for($user);

        if (count($branchOptions) > 1) {
            $selects[] = $this->select('branch', array_map(static fn (array $branch): array => ['value' => $branch['uuid'], 'label' => $branch['name']], $branchOptions));
        }

        foreach (self::FILTERS as $filter) {
            if (! in_array($filter, $supported, true)) {
                continue;
            }

            $selects[] = $this->select($filter, match ($filter) {
                'source' => $filters->sources(),
                'status' => $filters->statuses(),
                'employee' => $filters->employees($user),
                'service' => $filters->services(),
                default => $filters->categories(),
            });
        }

        return $selects;
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return array{name: string, label: string, all: string, options: list<array{value: string, label: string}>}
     */
    private function select(string $name, array $options): array
    {
        return [
            'name' => $name,
            'label' => __('manager_reports.std.filters.'.$name),
            'all' => __('manager_reports.std.filters.all_'.$name),
            'options' => $options,
        ];
    }

    /** @return array<string, mixed> */
    private function toolbar(ReportPeriod $period, string $locale): array
    {
        return [
            'presets' => array_map(fn (string $preset): array => [
                'key' => $preset,
                'label' => __('manager_reports.std.period.'.$preset),
                'active' => $period->preset === $preset,
            ], ReportPeriod::PRESETS),
            'current' => $this->describe($period->current, $locale),
            'previous' => __('manager_reports.std.period.compared', ['name' => __('manager_reports.std.compare.'.$period->comparisonKey()), 'period' => $this->describe($period->previous, $locale)]),
            'today' => CarbonImmutable::now($period->current->timezone)->toDateString(),
        ];
    }

    private function describe(DateRange $range, string $locale): string
    {
        return $range->days() === 1
            ? $range->from->locale($locale)->isoFormat('D MMM YYYY')
            : $range->from->locale($locale)->isoFormat($range->from->year === $range->to->year ? 'D MMM' : 'D MMM YYYY').' – '.$range->to->locale($locale)->isoFormat('D MMM YYYY');
    }

    /**
     * What every state of the page shares.
     *
     * @return array<string, mixed>
     */
    private function shell(): array
    {
        $offers = app(FeatureOffer::class);

        return [
            'advancedUrl' => route('center.advanced-reports'),
            // The real plan that adds it, only while the center lacks it.
            'advancedLock' => $offers->isLocked('reports_advanced') ? $offers->lockLabel('reports_advanced') : null,
        ];
    }

    /** @param list<string> $permissions */
    private function holdsAll(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            $required = Permission::tryFrom($permission);

            if (! $required instanceof Permission || ! $user->hasPermission($required)) {
                return false;
            }
        }

        return true;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }
}
