<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Roles;

use App\Kernel\Platform\Authorization\PlatformPermission;
use App\Kernel\Platform\Identity\Actions\ManagePlatformRoles;
use App\Kernel\Platform\Identity\Models\PlatformRole;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Platform roles: named, explicit bundles of platform permissions. The system
 * Super Admin role is shown but never edited — it always holds everything.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;

    /** Permission codes, grouped the way an operator thinks about them. */
    public const GROUPS = [
        'overview' => ['platform.dashboard.view'],
        'centers' => ['platform.center.view', 'platform.center.manage', 'platform.center_user.view', 'platform.center_user.manage', 'platform.entitlement.manage', 'platform.usage.manage'],
        'commercial' => ['platform.plan.manage', 'platform.subscription.manage', 'platform.billing.manage'],
        'support' => ['platform.support.view', 'platform.support.manage'],
        'operations' => ['platform.operations.view', 'platform.operations.manage', 'platform.provider.view', 'platform.audit.view'],
        'content' => ['platform.cms.manage'],
        'communication' => ['platform.announcement.send'],
        'platform' => ['platform.user.manage', 'platform.settings.manage', 'platform.branding.manage', 'platform.ai.manage', 'platform.security.manage'],
    ];

    #[Url(except: false)]
    public bool $archived = false;

    /** `create`, `edit:<id>`, `view:<id>`, `archive:<id>`, `restore:<id>`, `delete:<id>`. */
    public ?string $panel = null;

    /** @var array<string, string> */
    public array $name = ['en' => '', 'ar' => '', 'ckb' => ''];

    /** @var array<string, string> */
    public array $description = ['en' => '', 'ar' => '', 'ckb' => ''];

    /** @var array<int, string> */
    public array $permissions = [];

    public string $template = '';

    public string $reason = '';

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.user.manage');
        $this->closePanel();
        [$kind, $id] = array_pad(explode(':', $panel, 2), 2, '');
        if (in_array($kind, ['edit', 'view'], true)) {
            $role = $this->role((int) $id);
            $this->name = array_merge(['en' => '', 'ar' => '', 'ckb' => ''], $role->name);
            $this->description = array_merge(['en' => '', 'ar' => '', 'ckb' => ''], $role->description ?? []);
            $this->permissions = $role->permissions();
        }
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('name', 'description', 'permissions', 'template', 'reason');
        $this->resetValidation();
    }

    public function updatedTemplate(): void
    {
        $preset = ManagePlatformRoles::TEMPLATES[$this->template] ?? null;
        if ($preset === null) {
            return;
        }
        $this->permissions = $preset;
        foreach (['en', 'ar', 'ckb'] as $locale) {
            if (trim($this->name[$locale] ?? '') === '') {
                $this->name[$locale] = (string) __('platform_permissions.templates.'.$this->template, [], $locale);
            }
        }
    }

    public function toggleGroup(string $group, bool $on): void
    {
        $codes = self::GROUPS[$group] ?? [];
        $this->permissions = $on
            ? array_values(array_unique([...$this->permissions, ...$codes]))
            : array_values(array_diff($this->permissions, $codes));
    }

    public function save(ManagePlatformRoles $roles): void
    {
        $actor = $this->requirePlatformPermission('platform.user.manage');
        $this->validate([
            'name.en' => ['required', 'string', 'max:190'],
            'name.ar' => ['nullable', 'string', 'max:190'],
            'name.ckb' => ['nullable', 'string', 'max:190'],
            'description.*' => ['nullable', 'string', 'max:190'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'in:'.implode(',', PlatformPermission::codes())],
        ], [], [
            'name.en' => __('sadmin_roles.fields.name'),
            'permissions' => __('sadmin_roles.fields.permissions'),
        ]);

        try {
            if ($this->panel === 'create') {
                $roles->create($this->name, $this->description, array_values($this->permissions), $actor);
                $message = __('sadmin_roles.saved.created');
            } else {
                $roles->update($this->role((int) substr((string) $this->panel, 5)), $this->name, $this->description, array_values($this->permissions), $actor);
                $message = __('sadmin_roles.saved.updated');
            }
        } catch (DomainException $exception) {
            $this->addError('permissions', $exception->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', $message);
    }

    public function confirm(ManagePlatformRoles $roles): void
    {
        $actor = $this->requirePlatformPermission('platform.user.manage');
        [$kind, $id] = array_pad(explode(':', (string) $this->panel, 2), 2, '');
        $role = $this->role((int) $id);
        if ($kind === 'archive') {
            $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => __('sadmin_roles.fields.reason')]);
        }

        try {
            match ($kind) {
                'archive' => $roles->archive($role, $actor, $this->reason),
                'restore' => $roles->restore($role, $actor),
                'delete' => $roles->delete($role, $actor),
                default => abort(404),
            };
        } catch (DomainException $exception) {
            $this->addError('reason', $exception->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', __('sadmin_roles.saved.'.$kind));
    }

    public function render(): mixed
    {
        $actor = $this->requirePlatformPermission('platform.user.manage');
        $roles = PlatformRole::query()->withCount('users')
            ->when(! $this->archived, fn ($q) => $q->whereNull('archived_at'))
            ->when($this->archived, fn ($q) => $q->whereNotNull('archived_at'))
            ->orderByDesc('is_system')->orderBy('key')->get();

        return view('livewire.sadmin.roles.index', [
            'roles' => $roles,
            'rolePermissions' => $roles->mapWithKeys(fn (PlatformRole $role): array => [$role->id => $role->permissions()])->all(),
            'held' => $actor->permissions(),
            'target' => $this->panel !== null && str_contains($this->panel, ':') ? PlatformRole::query()->withCount('users')->find((int) explode(':', $this->panel, 2)[1]) : null,
            'archivedCount' => PlatformRole::query()->whereNotNull('archived_at')->count(),
        ]);
    }

    private function role(int $id): PlatformRole
    {
        /** @var PlatformRole $role */
        $role = PlatformRole::query()->findOrFail($id);

        return $role;
    }
}
