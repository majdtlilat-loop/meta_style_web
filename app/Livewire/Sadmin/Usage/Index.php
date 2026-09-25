<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Usage;

use App\Kernel\Audit\Actor;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Usage\Actions\SetTenantLimitOverride;
use App\Kernel\Usage\Models\TenantLimitOverride;
use App\Kernel\Usage\Models\TenantUsageProjection;
use App\Kernel\Usage\UsageCatalog;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Usage across every center. The rows are control-plane projections — a
 * reporting copy that may lag the tenant counter, which stays authoritative —
 * so each shows when it was taken.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    #[Url(except: '')]
    public string $center = '';

    #[Url(except: '')]
    public string $resourceFilter = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    /** `override` or `reset:<id>`. */
    public ?string $panel = null;

    public string $tenantId = '';

    public string $resource = '';

    public string $allowance = '';

    public bool $unlimited = false;

    public bool $enforceImmediately = false;

    public string $reason = '';

    /** @var array<int, string> */
    public array $clearReasons = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['center', 'resourceFilter', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('center', 'resourceFilter', 'statusFilter');
        $this->resetPage();
    }

    public function openOverride(string $tenantId = '', string $resource = ''): void
    {
        $this->requirePlatformPermission('platform.usage.manage');
        $this->closePanel();
        $this->tenantId = $tenantId;
        $this->resource = $resource;
        $this->panel = 'override';
    }

    public function openReset(int $overrideId): void
    {
        $this->requirePlatformPermission('platform.usage.manage');
        $this->closePanel();
        $this->panel = 'reset:'.TenantLimitOverride::query()->findOrFail($overrideId)->id;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('tenantId', 'resource', 'allowance', 'unlimited', 'enforceImmediately', 'reason');
        $this->resetValidation();
    }

    public function save(SetTenantLimitOverride $set, UsageCatalog $catalog): void
    {
        $user = $this->requirePlatformPermission('platform.usage.manage');
        $data = $this->validate([
            'tenantId' => ['required', 'uuid'],
            'resource' => ['required', 'string', 'in:'.implode(',', $catalog->codes())],
            'allowance' => [$this->unlimited ? 'nullable' : 'required', 'nullable', 'integer', 'min:0'],
            'unlimited' => ['boolean'],
            'enforceImmediately' => ['boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $set($data['tenantId'], $data['resource'], $data['unlimited'] ? null : (int) $data['allowance'], Actor::platform($user), $data['reason'], $data['enforceImmediately']);
        $this->closePanel();
        session()->flash('notice', __('sadmin_usage.saved'));
    }

    public function clear(int $overrideId, SetTenantLimitOverride $set): void
    {
        $user = $this->requirePlatformPermission('platform.usage.manage');
        $data = $this->validate(["clearReasons.{$overrideId}" => ['required', 'string', 'min:5', 'max:1000']], [], ["clearReasons.{$overrideId}" => __('sadmin_usage.fields.reason')]);
        $override = TenantLimitOverride::query()->findOrFail($overrideId);
        $set->clear($override->tenant_id, $override->resource, Actor::platform($user), $data['clearReasons'][$overrideId]);
        unset($this->clearReasons[$overrideId]);
        $this->closePanel();
        session()->flash('notice', __('sadmin_usage.cleared'));
    }

    public function render(UsageCatalog $catalog): mixed
    {
        $this->requirePlatformPermission('platform.usage.manage');

        $projections = TenantUsageProjection::query()
            ->when($this->center !== '', fn (Builder $q) => $q->where('tenant_id', $this->center))
            ->when($this->resourceFilter !== '', fn (Builder $q) => $q->where('resource', $this->resourceFilter))
            ->when($this->statusFilter !== '', fn (Builder $q) => $q->where('status', $this->statusFilter))
            ->orderByRaw("CASE status WHEN 'exhausted' THEN 0 WHEN 'high' THEN 1 WHEN 'warning' THEN 2 ELSE 3 END")
            ->orderByDesc('percent')
            ->orderByDesc('projected_at')
            ->paginate(30);

        return view('livewire.sadmin.usage.index', [
            'projections' => $projections,
            'tenants' => TenantModel::query()->orderBy('name')->get(['id', 'name']),
            'overrides' => TenantLimitOverride::query()
                ->when($this->center !== '', fn (Builder $q) => $q->where('tenant_id', $this->center))
                ->latest('updated_at')
                ->limit(50)
                ->get(),
            'resources' => $catalog->codes(),
            'enforced' => collect($catalog->codes())->mapWithKeys(fn (string $code): array => [$code => $catalog->isEnforced($code)]),
            'resetting' => $this->panel !== null && str_starts_with($this->panel, 'reset:')
                ? TenantLimitOverride::query()->find((int) substr($this->panel, 6))
                : null,
            'hasFilters' => $this->center !== '' || $this->resourceFilter !== '' || $this->statusFilter !== '',
        ]);
    }
}
