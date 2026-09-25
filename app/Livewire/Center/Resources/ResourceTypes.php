<?php

declare(strict_types=1);

namespace App\Livewire\Center\Resources;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Resources\Application\Actions\SaveResourceType;
use App\Modules\Resources\Domain\Models\ResourceType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Resource TYPES: "Barber chair", "Laser machine", "Treatment room".
 *
 * A classification the whole center shares (not branch-scoped); services are
 * defined against a type and booking picks a concrete resource. Retiring one
 * is refused while services still require it or live resources belong to it —
 * {@see SaveResourceType::archive()} says which, in a sentence.
 */
final class ResourceTypes extends Component
{
    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    /** @var array<string, string> */
    public array $name = [];

    /** @var array<string, string> */
    public array $description = [];

    public bool $isActive = true;

    public string $sortOrder = '0';

    public bool $showArchived = false;

    public string $notice = '';

    public string $noticeTone = 'success';

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $type = ResourceType::query()->where('uuid', $uuid)->first();

        if (! $type instanceof ResourceType || $type->isArchived()) {
            return;
        }

        $this->resetForm();
        $this->editing = $type->uuid;
        $this->name = $type->name->all();
        $this->description = $type->description?->all() ?? [];
        $this->isActive = $type->is_active;
        $this->sortOrder = (string) $type->sort_order;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(SaveResourceType $save): void
    {
        $this->validate([
            'name.'.app(TenantLocales::class)->default() => ['required', 'string', 'max:190'],
            'name.*' => ['nullable', 'string', 'max:190'],
            'description.*' => ['nullable', 'string', 'max:500'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
        ], [], [
            'name.*' => __('manager_staff.fields.name'),
            'description.*' => __('manager_staff.resources.description'),
            'sortOrder' => __('manager_staff.branches.sort_order'),
        ]);

        $existing = $this->editing !== null ? ResourceType::query()->where('uuid', $this->editing)->first() : null;

        try {
            $save($this->name, $this->user(), $existing, array_filter($this->description, static fn ($value): bool => trim((string) $value) !== ''), $this->isActive, (int) $this->sortOrder);
        } catch (AuthorizationException|ValidationException $e) {
            $this->addError('form', $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage());

            return;
        }

        $this->flash($existing === null ? __('manager_staff.resources.type_created') : __('manager_staff.resources.type_saved'));
        $this->closeForm();
    }

    public function archive(string $uuid, SaveResourceType $save): void
    {
        $this->lifecycle($uuid, fn (ResourceType $type) => $save->archive($type, $this->user()), __('manager_staff.resources.type_archived'));
    }

    public function restore(string $uuid, SaveResourceType $save): void
    {
        $this->lifecycle($uuid, fn (ResourceType $type) => $save->restore($type, $this->user()), __('manager_staff.resources.type_restored'));
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(TenantLocales $locales): mixed
    {
        $user = $this->user();
        $canView = $user->hasPermission(Permission::ResourceView);

        $types = $canView
            ? ResourceType::query()
                ->withCount(['requirements', 'resources' => fn ($q) => $q->whereNull('archived_at')])
                ->when(! $this->showArchived, fn ($q) => $q->whereNull('archived_at'))
                ->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        return view('livewire.center.resources.resource-types', [
            'canManage' => $user->hasPermission(Permission::ResourceManage),
            'rows' => $types->map(static fn (ResourceType $type): array => [
                'uuid' => $type->uuid,
                'name' => $type->name->get(),
                'description' => $type->description?->get(),
                'state' => $type->isArchived() ? 'archived' : ($type->is_active ? 'active' : 'inactive'),
                'resources' => (int) $type->getAttribute('resources_count'),
                'services' => (int) $type->getAttribute('requirements_count'),
            ])->all(),
            'archivedCount' => $canView ? ResourceType::query()->whereNotNull('archived_at')->count() : 0,
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
        ]);
    }

    private function lifecycle(string $uuid, callable $action, string $success): void
    {
        $type = ResourceType::query()->where('uuid', $uuid)->first();

        if (! $type instanceof ResourceType) {
            return;
        }

        try {
            $action($type);
            $this->flash($success);
        } catch (AuthorizationException|ValidationException $e) {
            $this->flash($e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage(), 'danger');
        }
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'name', 'description', 'isActive', 'sortOrder');
        $this->resetValidation();
    }

    private function user(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
