<?php

declare(strict_types=1);

namespace App\Livewire\Center\Resources;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Staff\StaffOptions;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Resources\Application\Actions\SaveResource;
use App\Modules\Resources\Domain\Data\ResourceInput;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ResourceType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Chairs, rooms and devices, branch by branch.
 *
 * Every lookup is branch-scoped, so a resource at a branch the viewer does not
 * run is simply not found; every save goes through {@see SaveResource}, which
 * takes the branch lock and refuses to cut capacity below what is booked.
 * Editing keeps the resource's active flag and order — the form carries both.
 */
final class ResourceList extends Component
{
    public string $filterBranch = '';

    public string $filterType = '';

    /** current (live and inactive) | archived */
    public string $filterStatus = 'current';

    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    /** @var array<string, string> */
    public array $name = [];

    public string $type = '';

    public string $branch = '';

    public string $department = '';

    public string $capacity = '1';

    public string $sortOrder = '0';

    public bool $isActive = true;

    public string $notice = '';

    public string $noticeTone = 'success';

    public function create(): void
    {
        $this->resetForm();
        $branches = StaffOptions::branches($this->user());

        if (count($branches) === 1) {
            $this->branch = $branches[0]['uuid'];
        }

        $this->showForm = true;
    }

    public function edit(string $uuid): void
    {
        $resource = $this->resource($uuid);

        if (! $resource instanceof OperationalResource || $resource->isArchived()) {
            return;
        }

        $this->resetForm();
        $this->editing = $resource->uuid;
        $this->name = $resource->name->all();
        $this->type = (string) $resource->type?->uuid;
        $this->branch = (string) $resource->branch?->uuid;
        $this->department = (string) $resource->department?->uuid;
        $this->capacity = (string) $resource->capacity;
        $this->sortOrder = (string) $resource->sort_order;
        $this->isActive = $resource->is_active;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(SaveResource $save): void
    {
        $this->validate([
            'name.'.app(TenantLocales::class)->default() => ['required', 'string', 'max:190'],
            'name.*' => ['nullable', 'string', 'max:190'],
            'type' => ['required', 'string'],
            'branch' => ['required', 'string'],
            'department' => ['nullable', 'string'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
        ], [], [
            'name.*' => __('manager_staff.fields.name'),
            'type' => __('manager_staff.resources.type'),
            'branch' => __('ui.fields.branch'),
            'capacity' => __('manager_staff.resources.capacity'),
            'sortOrder' => __('manager_staff.branches.sort_order'),
        ]);

        $existing = $this->editing !== null ? $this->resource($this->editing) : null;

        try {
            $save(ResourceInput::fromArray([
                'resource_type' => $this->type,
                'branch' => $this->branch,
                'department' => $this->department !== '' ? $this->department : null,
                'name' => $this->name,
                'capacity' => (int) $this->capacity,
                'is_active' => $this->isActive,
                'sort_order' => (int) $this->sortOrder,
            ]), $this->user(), $existing);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $target = ['resource_type' => 'type', 'branch' => 'branch', 'department' => 'department', 'capacity' => 'capacity', 'name' => 'name'][$field] ?? 'form';
                $this->addError($target, (string) ($messages[0] ?? ''));
            }

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->flash($existing === null ? __('manager_staff.resources.created') : __('manager_staff.resources.saved'));
        $this->closeForm();
    }

    public function archive(string $uuid, SaveResource $save): void
    {
        $this->lifecycle($uuid, fn (OperationalResource $resource) => $save->archive($resource, $this->user()), __('manager_staff.resources.archived'));
    }

    public function restore(string $uuid, SaveResource $save): void
    {
        $this->lifecycle($uuid, fn (OperationalResource $resource) => $save->restore($resource, $this->user()), __('manager_staff.resources.restored'));
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(): mixed
    {
        $user = $this->user();
        $query = OperationalResource::query()->with(['type', 'branch', 'department']);
        $user->branchScope()->applyTo($query, 'branch_id');

        // Re-checked on every request: this component answers its own
        // Livewire calls, not only the page's first render.
        if (! $user->hasPermission(Permission::ResourceView)) {
            $query->whereRaw('1 = 0');
        }

        if ($this->filterBranch !== '') {
            $query->whereHas('branch', fn ($q) => $q->where('uuid', $this->filterBranch));
        }

        if ($this->filterType !== '') {
            $query->whereHas('type', fn ($q) => $q->where('uuid', $this->filterType));
        }

        if ($this->filterStatus === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $types = ResourceType::query()->orderBy('sort_order')->orderBy('id')->get();

        return view('livewire.center.resources.resource-list', [
            'canManage' => $user->hasPermission(Permission::ResourceManage),
            'rows' => $query->orderBy('branch_id')->orderBy('sort_order')->orderBy('id')->limit(300)->get()
                ->map(static fn (OperationalResource $resource): array => [
                    'uuid' => $resource->uuid,
                    'name' => $resource->name->get(),
                    'type' => $resource->type?->name->get() ?? '—',
                    'branch' => $resource->branch?->name->get() ?? '—',
                    'department' => $resource->department?->name->get(),
                    'capacity' => $resource->capacity,
                    'state' => $resource->isArchived() ? 'archived' : ($resource->is_active ? 'active' : 'inactive'),
                ])->all(),
            'branches' => StaffOptions::branches($user),
            'filterTypes' => $types->map(static fn (ResourceType $type): array => ['uuid' => $type->uuid, 'name' => $type->name->get()])->all(),
            'formTypes' => $types->filter(static fn (ResourceType $type): bool => $type->isBookable())
                ->map(static fn (ResourceType $type): array => ['uuid' => $type->uuid, 'name' => $type->name->get()])->values()->all(),
            'departments' => $this->showForm
                ? Department::query()->active()->get()->map(static fn (Department $department): array => ['uuid' => $department->uuid, 'name' => $department->name->get()])->all()
                : [],
            'locales' => app(TenantLocales::class)->enabled(),
            'primaryLocale' => app(TenantLocales::class)->default(),
        ]);
    }

    private function lifecycle(string $uuid, callable $action, string $success): void
    {
        $resource = $this->resource($uuid);

        if (! $resource instanceof OperationalResource) {
            return;
        }

        try {
            $action($resource);
            $this->flash($success);
        } catch (AuthorizationException|ValidationException $e) {
            $this->flash($e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage(), 'danger');
        }
    }

    private function resource(string $uuid): ?OperationalResource
    {
        $query = OperationalResource::query()->with(['type', 'branch', 'department'])->where('uuid', $uuid);
        $this->user()->branchScope()->applyTo($query, 'branch_id');
        $resource = $query->first();

        return $resource instanceof OperationalResource ? $resource : null;
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'name', 'type', 'branch', 'department', 'capacity', 'sortOrder', 'isActive');
        $this->resetValidation();
    }

    private function user(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
