<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Support;

use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\PlatformSupport\Application\Actions\CreateSupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The platform support queue: centers asking Meta Style for help. Separate
 * from WhatsApp, customer CRM and a center's own conversations.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    public const STATUSES = ['open', 'in_progress', 'waiting_center', 'resolved', 'closed'];

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    /** `active` (anything not resolved or closed) is the working view. */
    #[Url(except: 'active')]
    public string $status = 'active';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $center = '';

    public bool $creating = false;

    public string $tenantId = '';

    public string $subject = '';

    public string $body = '';

    public string $newPriority = 'normal';

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'priority', 'search', 'center'], true)) {
            $this->resetPage();
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, ['active', 'all', ...self::STATUSES], true) ? $status : 'active';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('priority', 'search', 'center');
        $this->status = 'active';
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->requirePlatformPermission('platform.support.manage');
        $this->closeCreate();
        $this->tenantId = $this->center;
        $this->creating = true;
    }

    public function closeCreate(): void
    {
        $this->creating = false;
        $this->reset('tenantId', 'subject', 'body', 'newPriority');
        $this->resetValidation();
    }

    public function create(CreateSupportTicket $create): void
    {
        $user = $this->requirePlatformPermission('platform.support.manage');
        $data = $this->validate([
            'tenantId' => ['required', 'uuid'],
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
            'newPriority' => ['required', 'in:low,normal,high,urgent'],
        ]);
        $ticket = $create($data['tenantId'], $data['subject'], $data['body'], $data['newPriority'], 'platform', (string) $user->getKey(), $user->name);
        $this->closeCreate();
        session()->flash('notice', __('sadmin_support.created'));

        $this->redirectRoute('superadmin.support.show', ['ticket' => $ticket->uuid], navigate: true);
    }

    public function render(): mixed
    {
        $user = $this->requirePlatformPermission('platform.support.view');

        $tickets = $this->filtered()
            ->with('tenant:id,name')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->latest('last_activity_at')
            ->orderByDesc('id')
            ->paginate(20);

        $counts = $this->filtered(withStatus: false)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        return view('livewire.sadmin.support.index', [
            'tickets' => $tickets,
            'counts' => $counts + [
                'active' => ($counts['open'] ?? 0) + ($counts['in_progress'] ?? 0) + ($counts['waiting_center'] ?? 0),
                'all' => array_sum($counts),
            ],
            'tenants' => TenantModel::query()->orderBy('name')->get(['id', 'name']),
            'canManage' => $user->hasPermission('platform.support.manage'),
            'hasFilters' => $this->priority !== '' || $this->search !== '' || $this->center !== '' || $this->status !== 'active',
        ]);
    }

    /**
     * @return Builder<SupportTicket>
     */
    private function filtered(bool $withStatus = true): Builder
    {
        return SupportTicket::query()
            ->when($withStatus && $this->status === 'active', fn (Builder $q) => $q->whereIn('status', ['open', 'in_progress', 'waiting_center']))
            ->when($withStatus && in_array($this->status, self::STATUSES, true), fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->priority !== '', fn (Builder $q) => $q->where('priority', $this->priority))
            ->when($this->center !== '', fn (Builder $q) => $q->where('tenant_id', $this->center))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('subject', 'like', '%'.$this->search.'%')
                ->orWhere('reference', 'like', '%'.$this->search.'%')));
    }
}
