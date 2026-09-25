<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Operations;

use App\Kernel\Diagnostics\ProductionReadiness;
use App\Kernel\Diagnostics\ReadinessCheck;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\PlatformOperations\Application\RefreshOperationalProjections;
use App\Modules\PlatformOperations\Domain\Models\TenantOperationalProjection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Is the platform fit to run, and is every center healthy?
 *
 * Readiness is always judged by PRODUCTION rules, so a local machine shows
 * what would still stop a release. Center health comes from control-plane
 * projections; nothing here opens a tenant database.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    public function refresh(RefreshOperationalProjections $refresh): void
    {
        $this->requirePlatformPermission('platform.operations.manage');
        $count = $refresh();
        session()->flash('notice', trans_choice('sadmin_operations.refreshed', $count, ['count' => $count]));
    }

    public function render(ProductionReadiness $readiness): mixed
    {
        $user = $this->requirePlatformPermission('platform.operations.view');
        $checks = $readiness->checks(true);

        $projections = TenantOperationalProjection::query()
            ->orderByRaw("CASE health WHEN 'critical' THEN 0 WHEN 'attention' THEN 1 ELSE 2 END")
            ->orderByDesc('projected_at')
            ->paginate(30);

        $operations = DB::connection('control')->table('tenant_operations')->latest('started_at')->limit(25)->get();

        $tenantIds = $projections->getCollection()->pluck('tenant_id')->merge($operations->pluck('tenant_id'))->unique()->values();

        return view('livewire.sadmin.operations.index', [
            'checks' => $checks,
            'checkCounts' => collect($checks)->countBy(fn (ReadinessCheck $check): string => $check->status)->all(),
            'projections' => $projections,
            'operations' => $operations,
            'centerNames' => TenantModel::query()->whereIn('id', $tenantIds)->pluck('name', 'id'),
            'environment' => (string) config('app.env'),
            'canManage' => $user->hasPermission('platform.operations.manage'),
        ]);
    }
}
