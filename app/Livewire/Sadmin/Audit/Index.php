<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Audit;

use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    public string $search = '';

    public string $category = '';

    public string $severity = '';

    #[Url(as: 'center', except: '')]
    public string $tenantId = '';

    /** A platform user id (from Platform users → activity) matches exactly; any other text searches. */
    #[Url(except: '')]
    public string $actor = '';

    public string $target = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?string $selectedUuid = null;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'severity', 'tenantId', 'actor', 'target', 'dateFrom', 'dateTo'], true)) {
            $this->resetPage();
        }
    }

    public function showDetails(string $uuid): void
    {
        $this->requirePlatformPermission('platform.audit.view');
        PlatformAuditLog::query()->where('uuid', $uuid)->firstOrFail();
        $this->selectedUuid = $uuid;
    }

    public function closeDetails(): void
    {
        $this->selectedUuid = null;
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'severity', 'tenantId', 'actor', 'target', 'dateFrom', 'dateTo');
        $this->resetPage();
    }

    public function render(): mixed
    {
        $this->requirePlatformPermission('platform.audit.view');

        $entries = PlatformAuditLog::query()
            ->when($this->search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('action', 'like', '%'.$this->search.'%')
                ->orWhere('reason', 'like', '%'.$this->search.'%')
                ->orWhere('correlation_id', 'like', '%'.$this->search.'%')))
            ->when($this->category !== '', fn ($q) => $q->where('category', $this->category))
            ->when($this->severity !== '', fn ($q) => $q->where('severity', $this->severity))
            ->when($this->tenantId !== '', fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->when(ctype_digit($this->actor), fn ($q) => $q->where('actor_type', 'platform')->where('actor_id', $this->actor))
            ->when($this->actor !== '' && ! ctype_digit($this->actor), fn ($q) => $q->where(fn ($inner) => $inner
                ->where('actor_label', 'like', '%'.$this->actor.'%')
                ->orWhere('actor_id', 'like', '%'.$this->actor.'%')
                ->orWhere('actor_type', 'like', '%'.$this->actor.'%')))
            ->when($this->target !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('target_label', 'like', '%'.$this->target.'%')
                ->orWhere('target_id', 'like', '%'.$this->target.'%')
                ->orWhere('target_type', 'like', '%'.$this->target.'%')))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('occurred_at', '>=', $this->dateFrom.' 00:00:00'))
            ->when($this->dateTo !== '', fn ($q) => $q->where('occurred_at', '<=', $this->dateTo.' 23:59:59'))
            ->latest('occurred_at')
            ->paginate(30);

        return view('livewire.sadmin.audit.index', [
            'entries' => $entries,
            'tenants' => TenantModel::query()->orderBy('name')->get(['id', 'name']),
            'hasFilters' => $this->search !== '' || $this->category !== '' || $this->severity !== '' || $this->tenantId !== ''
                || $this->actor !== '' || $this->target !== '' || $this->dateFrom !== '' || $this->dateTo !== '',
            'selectedEntry' => $this->selectedUuid === null
                ? null
                : PlatformAuditLog::query()->where('uuid', $this->selectedUuid)->first(),
        ]);
    }
}
