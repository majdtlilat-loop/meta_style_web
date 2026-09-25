<?php

declare(strict_types=1);

namespace App\Livewire\Center\Dashboard;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Time\DateRange;
use App\Modules\Booking\Application\DashboardAppointments;
use App\Modules\Customers\Application\DashboardNewestCustomers;
use App\Modules\Employees\Application\DashboardTeamSnapshot;
use App\Modules\Queue\Application\DashboardQueueSnapshot;
use App\Modules\Reports\Application\ManagerOverview;
use App\Modules\Sales\Application\DashboardRecentSales;
use App\Modules\ServiceJourney\Application\DashboardFloor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Everything the overview reads, gathered once per render.
 *
 * The PERIOD figures are a dozen report scans (current and previous range),
 * so a Livewire round-trip that only opens the custom-range form or changes
 * nothing about the range must not re-run them: they are kept for a minute
 * under a key naming everything they depend on — the tenant (in the key AND
 * through the tenant-tagged cache the facade resolves), the viewer, their
 * branch scope, what they may see, the range, the branch and the language.
 * "Refresh" drops the key. The NOW cards are a handful of indexed reads and
 * are always current: "who is waiting" must never be a minute old.
 *
 * Every read here is one of the modules' own branch- and permission-scoped
 * queries; this class decides only WHICH to ask, never what they return.
 */
final class DashboardData
{
    public const TTL_SECONDS = 60;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Entitlements $entitlements,
        private readonly ManagerOverview $overview,
        private readonly DashboardAppointments $appointments,
        private readonly DashboardQueueSnapshot $queue,
        private readonly DashboardFloor $floor,
        private readonly DashboardRecentSales $sales,
        private readonly DashboardNewestCustomers $customers,
        private readonly DashboardTeamSnapshot $team,
    ) {}

    /**
     * @return array{overview: array<string, mixed>, now: array<string, mixed>, gates: array<string, bool>, generated_at: string, failed: bool}
     */
    public function get(User $viewer, DateRange $range, ?string $branch, string $locale): array
    {
        $gates = $this->gates($viewer);

        try {
            /** @var array{overview: array<string, mixed>, generated_at: string} $period */
            $period = Cache::remember(
                $this->key($viewer, $range, $branch, $locale, $gates),
                self::TTL_SECONDS,
                fn (): array => [
                    'overview' => $this->overview->build($viewer, $range, $locale, $branch),
                    'generated_at' => CarbonImmutable::now()->utc()->toIso8601String(),
                ],
            );
            $failed = false;
        } catch (Throwable $failure) {
            // One failed read must not take the whole landing page down: the
            // period says it could not be loaded, "now" still renders.
            report($failure);
            $period = [
                'overview' => ['sections' => [], 'buckets' => [], 'previous_buckets' => []],
                'generated_at' => CarbonImmutable::now()->utc()->toIso8601String(),
            ];
            $failed = true;
        }

        return $period + ['now' => $this->now($viewer, $branch, $gates), 'gates' => $gates, 'failed' => $failed];
    }

    public function forget(User $viewer, DateRange $range, ?string $branch, string $locale): void
    {
        Cache::forget($this->key($viewer, $range, $branch, $locale, $this->gates($viewer)));
    }

    /**
     * The overview's own gates plus the "now" cards'.
     *
     * @return array<string, bool>
     */
    public function gates(User $viewer): array
    {
        $booking = $this->entitlements->enabled('booking');
        $pos = $this->entitlements->enabled('pos');

        return $this->overview->gates($viewer) + [
            'today' => $booking && ($viewer->hasPermission(Permission::AppointmentView) || $viewer->hasPermission(Permission::AppointmentViewOwn)),
            'floor' => $viewer->hasPermission(Permission::JourneyView) || $viewer->hasPermission(Permission::JourneyViewOwn),
            'queue_now' => $viewer->hasPermission(Permission::QueueView) && $this->entitlements->enabled('queue_management'),
            'recent_sales' => $viewer->hasPermission(Permission::SaleView) && $pos,
            'recent_customers' => $viewer->hasPermission(Permission::CustomerView),
            'team' => $viewer->hasPermission(Permission::StaffView),
            'team_bookings' => $booking && $viewer->hasPermission(Permission::AppointmentView),
            'finance' => $viewer->hasPermission(Permission::FinanceView) && $this->entitlements->enabled('finance'),
            'plan' => $viewer->hasPermission(Permission::SettingsView),
        ];
    }

    /**
     * @param  array<string, bool>  $gates
     * @return array<string, mixed>
     */
    private function now(User $viewer, ?string $branch, array $gates): array
    {
        return [
            'today' => $gates['today'] ? $this->appointments->today($viewer, $branch) : null,
            'upcoming' => $gates['today'] ? $this->appointments->upcoming($viewer, $branch) : null,
            'queue' => $gates['queue_now'] ? $this->queue->now($viewer, $branch) : null,
            'floor' => $gates['floor'] ? $this->floor->now($viewer, $branch) : null,
            'sales' => $gates['recent_sales'] ? $this->sales->latest($viewer, $branch) : null,
            'customers' => $gates['recent_customers'] ? $this->customers->latest($viewer) : null,
            'team' => $gates['team'] ? $this->team->activeCount($viewer, $branch) : null,
            'team_booked' => $gates['team_bookings'] ? $this->appointments->bookedEmployeesToday($viewer, $branch) : null,
        ];
    }

    /**
     * @param  array<string, bool>  $gates
     */
    private function key(User $viewer, DateRange $range, ?string $branch, string $locale, array $gates): string
    {
        return 'manager-overview:'.sha1((string) json_encode([
            $this->tenants->id(),
            $viewer->getKey(),
            $viewer->branchScope()->branchIds,
            $range->preset,
            $range->from->toDateString(),
            $range->to->toDateString(),
            $range->timezone,
            $branch,
            $locale,
            $gates,
        ]));
    }
}
