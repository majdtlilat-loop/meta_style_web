<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Entitlements;

use App\Kernel\Audit\Actor;
use App\Kernel\Entitlements\EntitlementCatalog;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\PlanEntitlement;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\SaasAdmin\Application\Actions\SetTenantEntitlement;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Capability overrides across every center: who was granted or refused what
 * beyond their plan, until when, and why — and where each capability sits in
 * the plans.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    #[Url(except: 'overrides')]
    public string $view = 'overrides';

    #[Url(except: '')]
    public string $center = '';

    #[Url(except: '')]
    public string $capability = '';

    #[Url(except: '')]
    public string $mode = '';

    /** `create`, `reset:<id>`. */
    public ?string $panel = null;

    public string $tenantId = '';

    public string $entitlement = '';

    public string $overrideMode = 'grant';

    public string $expiresAt = '';

    public string $reason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['center', 'capability', 'mode', 'view'], true)) {
            $this->resetPage();
        }
    }

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.entitlement.manage');
        $this->closePanel();
        if ($panel === 'create') {
            $this->tenantId = $this->center;
            $this->entitlement = $this->capability;
        }
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('tenantId', 'entitlement', 'overrideMode', 'expiresAt', 'reason');
        $this->resetValidation();
    }

    public function save(SetTenantEntitlement $set, EntitlementCatalog $catalog): void
    {
        $user = $this->requirePlatformPermission('platform.entitlement.manage');
        $data = $this->validate([
            'tenantId' => ['required', 'string', 'exists:control.tenants,id'],
            'entitlement' => ['required', 'string', 'in:'.implode(',', $catalog->keys())],
            'overrideMode' => ['required', 'in:grant,revoke'],
            'expiresAt' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [], [
            'tenantId' => __('sadmin_entitlements.fields.center'),
            'entitlement' => __('sadmin_entitlements.fields.capability'),
            'reason' => __('sadmin_entitlements.fields.reason'),
        ]);
        try {
            $set($data['tenantId'], $data['entitlement'], OverrideMode::from($data['overrideMode']), Actor::platform($user), $data['reason'], $data['expiresAt'] ? Carbon::parse($data['expiresAt']) : null);
        } catch (DomainException $exception) {
            $this->addError('reason', $exception->getMessage());

            return;
        }
        $this->closePanel();
        session()->flash('notice', __('sadmin_entitlements.saved'));
    }

    public function clearOverride(SetTenantEntitlement $set): void
    {
        $user = $this->requirePlatformPermission('platform.entitlement.manage');
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [], ['reason' => __('sadmin_entitlements.fields.reason')]);
        $override = TenantEntitlementOverride::query()->findOrFail((int) substr((string) $this->panel, 6));
        $set->clear($override->tenant_id, $override->entitlement, Actor::platform($user), $this->reason);
        $this->closePanel();
        session()->flash('notice', __('sadmin_entitlements.cleared'));
    }

    public function render(EntitlementCatalog $catalog): mixed
    {
        $user = $this->requirePlatformPermission('platform.entitlement.manage');

        $overrides = TenantEntitlementOverride::query()
            ->when($this->center !== '', fn (Builder $q) => $q->where('tenant_id', $this->center))
            ->when($this->capability !== '', fn (Builder $q) => $q->where('entitlement', $this->capability))
            ->when(in_array($this->mode, ['grant', 'revoke'], true), fn (Builder $q) => $q->where('mode', $this->mode))
            ->latest('updated_at')
            ->paginate(25);

        $plans = Plan::query()->orderBy('sort_order')->get();
        $inPlans = PlanEntitlement::query()->get()->groupBy('entitlement')->map(fn ($rows) => $rows->pluck('plan_id')->all())->all();

        return view('livewire.sadmin.entitlements.index', [
            'overrides' => $overrides,
            'centerNames' => TenantModel::query()->whereIn('id', $overrides->getCollection()->pluck('tenant_id'))->pluck('name', 'id'),
            'centers' => TenantModel::query()->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name']),
            'catalog' => collect($catalog->keys())->groupBy(fn (string $key): string => $catalog->category($key))->all(),
            'keys' => $catalog->keys(),
            'plans' => $plans,
            'inPlans' => $inPlans,
            'overrideCounts' => TenantEntitlementOverride::query()->selectRaw('entitlement, mode, COUNT(*) AS aggregate')->groupBy('entitlement', 'mode')->get()
                ->groupBy('entitlement')->map(fn ($rows) => $rows->pluck('aggregate', 'mode')->map(fn ($v) => (int) $v)->all())->all(),
            'canManage' => $user->hasPermission('platform.entitlement.manage'),
            'target' => $this->panel !== null && str_starts_with($this->panel, 'reset:') ? TenantEntitlementOverride::query()->find((int) substr($this->panel, 6)) : null,
        ]);
    }
}
