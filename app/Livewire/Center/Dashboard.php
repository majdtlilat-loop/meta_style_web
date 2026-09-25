<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Livewire\Center\Dashboard\BranchOptions;
use App\Livewire\Center\Dashboard\DashboardCards;
use App\Livewire\Center\Dashboard\DashboardData;
use App\Livewire\Center\Dashboard\DashboardPresenter;
use App\Livewire\Center\Dashboard\PlanStatus;
use App\Livewire\Concerns\HasDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The manager's landing page.
 *
 * Two kinds of fact, kept apart:
 *
 *   the PERIOD   figures for the chosen date range against the comparable one,
 *                from the same read contracts the Standard Reports use
 *                (ManagerOverview) — so the two can never disagree;
 *   the NOW      what is happening at this moment: today's book, who is
 *                waiting, who is being served. No range applies to "now".
 *
 * Every read is a module's own branch- and permission-scoped query, and each
 * section appears only with its permission and the center's entitlement.
 * This component only orchestrates: the figures come from DashboardData
 * (cached briefly, so opening the custom-range form re-runs nothing), the
 * wording and links from the presenters.
 */
#[Layout('components.layouts.app')]
final class Dashboard extends Component
{
    use HasDateRange;

    /** Narrows every section to one of the viewer's branches; empty = all of them. */
    #[Url(as: 'branch', history: true)]
    public string $branch = '';

    protected function rangeTimezone(): string
    {
        return app(BranchOptions::class)->timezone($this->viewer(), $this->branch === '' ? null : $this->branch);
    }

    /** Drops the cached figures for the current view and reads them again. */
    public function refresh(DashboardData $data): void
    {
        $viewer = $this->viewer();
        $data->forget($viewer, $this->dateRange(), app(BranchOptions::class)->valid($viewer, $this->branch), app()->getLocale());
    }

    public function render(
        TenantContext $tenants,
        BranchOptions $branches,
        DashboardData $data,
        DashboardPresenter $presenter,
        DashboardCards $cards,
        PlanStatus $plan,
    ): View {
        $viewer = $this->viewer();
        $locale = app()->getLocale();
        $options = $branches->for($viewer);
        $branch = $branches->valid($viewer, $this->branch);

        if ($branch === null && $this->branch !== '') {
            $this->branch = '';
        }

        $period = $this->dateRange();
        $figures = $data->get($viewer, $period, $branch, $locale);
        $sections = $figures['overview']['sections'];
        $gates = $figures['gates'];
        $timezone = $branches->timezone($viewer, $branch);
        $live = $presenter->live($figures['now']);
        $kpis = $presenter->kpis($sections, $figures['now'], $gates, $period, $viewer, $locale);
        $charts = $cards->charts($sections, $figures['overview']['buckets']);
        $now = $cards->now($figures['now'], $branch === null && count($options) > 1, $locale);
        $hasNow = array_filter($now, static fn (?array $card): bool => $card !== null) !== [];

        return view('livewire.center.dashboard', [
            'tenant' => $tenants->require(),
            'period' => $period,
            'branchOptions' => count($options) > 1 ? $options : [],
            'plan' => $gates['plan'] ? $plan->chip($locale) : null,
            'failed' => $figures['failed'],
            'live' => $live,
            'kpis' => $kpis,
            'charts' => $charts,
            'now' => $now,
            'hasNow' => $hasNow,
            'isEmpty' => $live === [] && $kpis === [] && $charts === [] && ! $hasNow,
            'locked' => $cards->locked(static fn (string $permission): bool => ($code = Permission::tryFrom($permission)) !== null && $viewer->hasPermission($code)),
            'updatedAt' => CarbonImmutable::parse($figures['generated_at'])->setTimezone($timezone)->format('H:i'),
            'actions' => [
                'pos' => $gates['sales'] && $viewer->hasPermission(Permission::SaleCreate) ? $presenter->link('center.pos') : null,
                'booking' => $gates['bookings'] && $viewer->hasPermission(Permission::AppointmentCreate) ? $presenter->link('center.calendar') : null,
                'reports' => $viewer->hasPermission(Permission::ReportView) ? $presenter->link('center.reports') : null,
            ],
        ]);
    }

    private function viewer(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }
}
