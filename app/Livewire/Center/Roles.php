<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Actions\CreateRole;
use App\Kernel\Authorization\Actions\DeleteRole;
use App\Kernel\Authorization\Actions\RenameRole;
use App\Kernel\Authorization\Actions\UpdateRolePermissions;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Roles\RoleCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Roles and what each may do.
 *
 * A custom permission set IS a custom role: grants reach people only through
 * roles, never one by one (docs/06 §4). The catalog is served from code, so
 * the editor cannot drift from what the server enforces, and a code the viewer
 * does not hold is shown but cannot be ticked — the Action refuses it anyway.
 * The Owner role is read-only.
 */
#[Layout('components.layouts.app')]
final class Roles extends Component
{
    /** create | edit | view | rename | delete */
    public ?string $panel = null;

    /** The role the panel is about, by uuid. */
    #[Locked]
    public ?string $target = null;

    /** @var array<string, string> */
    public array $name = [];

    /** @var list<string> */
    public array $selected = [];

    public string $notice = '';

    public function openCreate(): void
    {
        $this->closePanel();
        $this->panel = 'create';
    }

    public function openRole(string $uuid): void
    {
        $role = $this->role($uuid);

        if (! $role instanceof Role) {
            return;
        }

        $this->closePanel();
        $this->target = $role->uuid;
        $this->selected = $role->permissionCodes();
        $this->panel = $this->mayEdit($role) ? 'edit' : 'view';
    }

    public function openRename(string $uuid): void
    {
        $role = $this->role($uuid);

        if (! $role instanceof Role || $role->is_system) {
            return;
        }

        $this->closePanel();
        $this->target = $role->uuid;
        $this->name = $role->name->all();
        $this->panel = 'rename';
    }

    public function openDelete(string $uuid): void
    {
        $role = $this->role($uuid);

        if (! $role instanceof Role || $role->is_system) {
            return;
        }

        $this->closePanel();
        $this->target = $role->uuid;
        $this->panel = 'delete';
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'target', 'name', 'selected');
        $this->resetValidation();
    }

    public function toggleGroup(string $group, bool $on): void
    {
        $codes = RoleCatalog::changeableIn($group, $this->actor()->permissions());

        $this->selected = $on
            ? array_values(array_unique([...$this->selected, ...$codes]))
            : array_values(array_diff($this->selected, $codes));
    }

    public function save(CreateRole $create, UpdateRolePermissions $update): void
    {
        if ($this->panel === 'create') {
            $this->validatePrimaryName();
        }

        try {
            if ($this->panel === 'create') {
                $role = $create($this->name, $this->actor()->hasPermission(Permission::RolePermissionsManage) ? $this->selected : [], $this->actor());
                $this->notice = __('manager_staff.roles.created', ['name' => $role->name->get()]);
            } elseif ($this->panel === 'edit' && ($role = $this->role((string) $this->target)) instanceof Role) {
                $update($role, array_values(array_unique($this->selected)), $this->actor());
                $this->notice = __('manager_staff.roles.saved', ['name' => $role->name->get()]);
            } else {
                return;
            }
        } catch (ValidationException $e) {
            $this->addErrorsFrom($e);

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->closePanel();
    }

    public function rename(RenameRole $rename): void
    {
        $role = $this->role((string) $this->target);

        if (! $role instanceof Role) {
            return;
        }

        $this->validatePrimaryName();

        try {
            $rename($role, $this->name, $this->actor());
        } catch (ValidationException $e) {
            $this->addErrorsFrom($e);

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->notice = __('manager_staff.roles.renamed', ['name' => $role->name->get()]);
        $this->closePanel();
    }

    public function delete(DeleteRole $delete): void
    {
        $role = $this->role((string) $this->target);

        if (! $role instanceof Role) {
            return;
        }

        $name = $role->name->get();

        try {
            $delete($role, $this->actor());
        } catch (ValidationException|AuthorizationException $e) {
            $this->addError('form', $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage());

            return;
        }

        $this->notice = __('manager_staff.roles.deleted', ['name' => $name]);
        $this->closePanel();
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(TenantLocales $locales): mixed
    {
        $user = $this->actor();
        $canView = $user->hasPermission(Permission::RoleView);
        $held = $user->permissions();
        $target = $this->target !== null && $canView ? $this->role($this->target) : null;

        $members = $canView
            ? DB::connection('tenant')->table('user_roles')->selectRaw('role_id, count(*) as total')->groupBy('role_id')->pluck('total', 'role_id')->all()
            : [];

        return view('livewire.center.roles', [
            'canView' => $canView,
            'canCreate' => $user->hasPermission(Permission::RoleCreate),
            'canManage' => $user->hasPermission(Permission::RolePermissionsManage),
            'canRename' => $user->hasPermission(Permission::RoleUpdate),
            'canDelete' => $user->hasPermission(Permission::RoleDelete),
            'roles' => $canView
                ? Role::query()->with('permissions')->orderByDesc('is_system')->orderBy('id')->get()
                    ->map(static fn (Role $role): array => RoleCatalog::card($role, (int) ($members[$role->id] ?? 0)))->all()
                : [],
            'targetRole' => $target instanceof Role ? RoleCatalog::card($target, (int) ($members[$target->id] ?? 0)) : null,
            'groups' => in_array($this->panel, ['create', 'edit', 'view'], true) ? RoleCatalog::groups($this->selected, $this->panel === 'view' ? [] : $held) : [],
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
        ])->title(__('ui.manager_nav.items.roles'));
    }

    private function mayEdit(Role $role): bool
    {
        return $role->key !== SystemRole::Owner->value
            && $this->actor()->hasPermission(Permission::RolePermissionsManage);
    }

    private function role(string $uuid): ?Role
    {
        if (! $this->actor()->hasPermission(Permission::RoleView)) {
            return null;
        }

        $role = Role::query()->with('permissions')->where('uuid', $uuid)->first();

        return $role instanceof Role ? $role : null;
    }

    /**
     * The name in the center's primary language is the one every fallback
     * shows, so it is required here even though the Action accepts any one.
     */
    private function validatePrimaryName(): void
    {
        $primary = app(TenantLocales::class)->default();

        $this->validate([
            'name.'.$primary => ['required', 'string', 'max:190'],
            'name.*' => ['nullable', 'string', 'max:190'],
        ], [], ['name.'.$primary => __('manager_staff.roles.name')]);
    }

    private function addErrorsFrom(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $this->addError(str_starts_with($field, 'name') ? $field : 'form', (string) ($messages[0] ?? ''));
        }
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
