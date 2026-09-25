<?php

declare(strict_types=1);

namespace App\Livewire\Center\Reports;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Reports\Application\Analytics\StandardAnalytics;
use App\Modules\Reports\Application\ReportPeriod;
use App\Modules\Reports\Application\StandardReports;
use Illuminate\Support\Facades\Cache;

/**
 * A Standard report view's analytics, kept for a minute.
 *
 * A view is a dozen report scans (two periods, several readers), and a
 * Livewire round-trip that only opens the custom-range form must not run
 * them again. The key names everything the figures depend on — the tenant
 * (in the key AND through the tenant-tagged cache the facade resolves), the
 * viewer, their branch scope and the permissions that open optional
 * sections, the report, both periods, the branch, the filters and the
 * language. "Refresh" drops it. Authorization always runs first: a cached
 * answer is never served to someone the report would refuse.
 */
final class ReportsData
{
    public const TTL_SECONDS = 60;

    /** The permissions that change what a view contains. */
    private const GATES = [
        Permission::ReportView, Permission::AppointmentView, Permission::JourneyView, Permission::SaleView,
        Permission::PaymentView, Permission::FinanceView, Permission::CustomerView, Permission::QueueView,
        Permission::ReviewView, Permission::LoyaltyView, Permission::PackageView, Permission::MembershipView,
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly StandardAnalytics $analytics,
        private readonly StandardReports $reports,
    ) {}

    /**
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    public function get(string $code, User $viewer, ReportPeriod $period, ?string $branch, array $filters, string $locale): array
    {
        // Every time, cached or not: the product, report.view and the
        // report's source permissions.
        $this->reports->authorize($code, $viewer);

        /** @var array<string, mixed> $analytics */
        $analytics = Cache::remember(
            $this->key($code, $viewer, $period, $branch, $filters, $locale),
            self::TTL_SECONDS,
            fn (): array => $this->analytics->build($code, $viewer, $period, $branch, $filters, $locale),
        );

        return $analytics;
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    public function forget(string $code, User $viewer, ReportPeriod $period, ?string $branch, array $filters, string $locale): void
    {
        Cache::forget($this->key($code, $viewer, $period, $branch, $filters, $locale));
    }

    /**
     * @param  array<string, string|null>  $filters
     */
    private function key(string $code, User $viewer, ReportPeriod $period, ?string $branch, array $filters, string $locale): string
    {
        ksort($filters);

        return 'standard-report:'.sha1((string) json_encode([
            $this->tenants->id(),
            $viewer->getKey(),
            $viewer->branchScope()->branchIds,
            array_map(static fn (Permission $permission): bool => $viewer->hasPermission($permission), self::GATES),
            $code,
            $period->preset,
            $period->current->from->toDateString(),
            $period->current->to->toDateString(),
            $period->previous->from->toDateString(),
            $period->previous->to->toDateString(),
            $period->current->timezone,
            $branch,
            array_filter($filters),
            $locale,
        ]));
    }
}
