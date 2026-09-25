<?php

declare(strict_types=1);

namespace App\Livewire\Center\Resources;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Resources\Application\Actions\SetServiceResourceRequirements;
use App\Modules\Resources\Domain\Models\ResourceType;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * What each service needs: "a laser session needs one treatment room and one
 * laser machine".
 *
 * Defined against a TYPE, never one machine — booking picks a free one. Saved
 * as the complete list through {@see SetServiceResourceRequirements}, which
 * locks every branch; bookings already made keep what they reserved.
 */
final class ServiceRequirements extends Component
{
    #[Locked]
    public ?string $service = null;

    /** @var array<int, array<string, mixed>> rows as the browser sent them */
    public array $rows = [];

    public string $notice = '';

    public string $noticeTone = 'success';

    public function open(string $uuid): void
    {
        $service = Service::query()->where('uuid', $uuid)->first();

        if (! $service instanceof Service) {
            return;
        }

        $this->resetValidation();
        $this->service = $service->uuid;
        $this->rows = ServiceResourceRequirement::query()
            ->with('type')
            ->where('service_id', $service->getKey())
            ->get()
            ->map(static fn (ServiceResourceRequirement $row): array => [
                'type' => (string) $row->type?->uuid,
                'quantity' => $row->quantity,
            ])
            ->values()->all();

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function close(): void
    {
        $this->reset('service', 'rows');
        $this->resetValidation();
    }

    public function addRow(): void
    {
        $this->rows[] = ['type' => '', 'quantity' => 1];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function save(SetServiceResourceRequirements $set): void
    {
        $service = $this->service !== null ? Service::query()->where('uuid', $this->service)->first() : null;

        if (! $service instanceof Service) {
            return;
        }

        $this->validate([
            'rows.*.quantity' => ['required', 'integer', 'min:1', 'max:255'],
        ], [], ['rows.*.quantity' => __('manager_staff.resources.quantity')]);

        $rows = array_values(array_filter($this->rows, static fn (array $row): bool => ($row['type'] ?? '') !== ''));

        try {
            $set($service, $rows, $this->user());
        } catch (AuthorizationException|ValidationException $e) {
            $this->addError('form', $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage());

            return;
        }

        $this->notice = __('manager_staff.resources.requirements_saved', ['service' => $service->name->get()]);
        $this->noticeTone = 'success';
        $this->close();
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(): mixed
    {
        $user = $this->user();
        $canView = $user->hasPermission(Permission::ResourceView);

        $requirements = $canView
            ? ServiceResourceRequirement::query()->with('type')->get()->groupBy('service_id')
            : collect();

        $services = $canView
            ? Service::query()->whereNull('archived_at')->orderBy('sort_order')->orderBy('id')->get(['id', 'uuid', 'name', 'is_active'])
            : collect();

        $types = $canView ? ResourceType::query()->active()->get() : collect();
        $current = $this->service !== null ? $services->firstWhere('uuid', $this->service) : null;

        return view('livewire.center.resources.service-requirements', [
            'canManage' => $user->hasPermission(Permission::ResourceManage),
            'services' => $services->map(static fn (Service $service): array => [
                'uuid' => $service->uuid,
                'name' => $service->name->get(),
                'active' => $service->is_active,
                'needs' => ($requirements->get($service->id) ?? collect())
                    ->map(static fn (ServiceResourceRequirement $row): string => $row->quantity.' × '.($row->type?->name->get() ?? '—'))
                    ->values()->all(),
            ])->all(),
            'types' => $types->map(static fn (ResourceType $type): array => ['uuid' => $type->uuid, 'name' => $type->name->get()])->values()->all(),
            'serviceName' => $current instanceof Service ? $current->name->get() : null,
        ]);
    }

    private function user(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
