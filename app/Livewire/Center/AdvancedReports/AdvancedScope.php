<?php

declare(strict_types=1);

namespace App\Livewire\Center\AdvancedReports;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\Exceptions\ReportingConnectionUnavailable;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Reporting\ReportReadRequest;
use App\Livewire\Center\Dashboard\BranchOptions;
use App\Modules\AdvancedReports\Application\PeriodWindows;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\ReportsAccess;
use App\View\AdvancedReports\AdvancedPeriod;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Throwable;

/**
 * The one slice every Advanced Reports component reads: the viewer, the
 * branch they chose (validated against their scope — a foreign or stale uuid
 * falls back to "all my branches", which is still only their scope), the
 * period in that branch's timezone and its comparison period.
 *
 * The page and each section resolve it the same way from the same inputs
 * (`range`, `from`, `to`, `comparison`, `branch`), so every card agrees.
 */
trait AdvancedScope
{
    protected function viewer(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }

    /** Presentation gate; the module re-checks (ReportsAccess::advanced) on every read. */
    protected function mayRead(): bool
    {
        return $this->viewer()->hasPermission(Permission::ReportView) && app(ReportsAccess::class)->advancedEnabled();
    }

    protected function branchUuid(): ?string
    {
        return app(BranchOptions::class)->valid($this->viewer(), $this->branch);
    }

    protected function advancedPeriod(): AdvancedPeriod
    {
        $timezone = app(BranchOptions::class)->timezone($this->viewer(), $this->branchUuid());

        return AdvancedPeriod::resolve($this->range, $this->from, $this->to, $this->comparison, $timezone);
    }

    /**
     * The authorized current request (Reporting target, branch scope
     * intersected by the factory) and its comparison on the same branches.
     * `$allBranches` ignores the branch filter (branch vs branch compares
     * every branch in scope).
     *
     * @return array{0: ReportReadRequest, 1: ReportReadRequest}
     */
    protected function reportRequests(AdvancedPeriod $period, bool $allBranches = false): array
    {
        $branch = $allBranches ? null : $this->branchUuid();
        $current = app(ReportRequestFactory::class)->make(
            $this->viewer()->branchScope(),
            $period->from->toDateString(),
            $period->to->toDateString(),
            $branch === null ? [] : [$branch],
            ReadTarget::Reporting,
        );

        return [$current, PeriodWindows::shift($current, $period->compareFrom->toDateString(), $period->compareTo->toDateString())];
    }

    /** A translated, non-technical message for a failed read. */
    protected function readFailure(Throwable $failure): string
    {
        return match (true) {
            $failure instanceof ReportingConnectionUnavailable => (string) __('manager_advanced.errors.reporting_unavailable'),
            $failure instanceof AuthorizationException => (string) __('manager_advanced.errors.forbidden'),
            $failure instanceof EntitlementRequired => (string) __('manager_advanced.errors.locked'),
            $failure instanceof InvalidArgumentException => (string) __('manager_advanced.errors.invalid_range'),
            default => $this->reported($failure),
        };
    }

    private function reported(Throwable $failure): string
    {
        report($failure);

        return (string) __('manager_advanced.errors.failed');
    }
}
