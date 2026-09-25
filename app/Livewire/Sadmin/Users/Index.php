<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Users;

use App\Kernel\Platform\Identity\Actions\ManagePlatformUsers;
use App\Kernel\Platform\Identity\Models\PlatformRole;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** The people who operate Meta Style, and what each of them may do. */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'active')]
    public string $show = 'active';

    #[Url(except: '')]
    public string $role = '';

    /** `invite`, `edit:<uuid>`, `block:<uuid>`, `unblock:<uuid>`, `archive:<uuid>`, `restore:<uuid>`, `mfa:<uuid>`, `link:<uuid>`. */
    public ?string $panel = null;

    public string $name = '';

    public string $email = '';

    /** @var list<int> */
    public array $roleIds = [];

    public string $reason = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'show', 'role'], true)) {
            $this->resetPage();
        }
    }

    public function setShow(string $show): void
    {
        $this->show = in_array($show, ['active', 'blocked', 'archived', 'all'], true) ? $show : 'active';
        $this->resetPage();
    }

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.user.manage');
        $this->closePanel();
        [$kind, $uuid] = array_pad(explode(':', $panel, 2), 2, '');
        if ($kind === 'edit') {
            $user = $this->target($uuid);
            $this->name = $user->name;
            $this->email = $user->email;
            $this->roleIds = array_map('intval', $user->roles()->pluck('platform_roles.id')->all());
        }
        $this->panel = $panel;
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('name', 'email', 'roleIds', 'reason');
        $this->resetValidation();
    }

    public function save(ManagePlatformUsers $users): void
    {
        $actor = $this->requirePlatformPermission('platform.user.manage');
        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'roleIds' => ['required', 'array', 'min:1'],
            'roleIds.*' => ['integer'],
        ], [], [
            'name' => __('sadmin_users.fields.name'),
            'email' => __('sadmin_users.fields.email'),
            'roleIds' => __('sadmin_users.fields.roles'),
        ]);

        try {
            if ($this->panel === 'invite') {
                $users->invite($this->name, $this->email, array_map('intval', $this->roleIds), $actor);
                $message = __('sadmin_users.saved.invited', ['email' => $this->email]);
            } else {
                $users->update($this->target(substr((string) $this->panel, 5)), $this->name, $this->email, array_map('intval', $this->roleIds), $actor);
                $message = __('sadmin_users.saved.updated');
            }
        } catch (DomainException $exception) {
            $this->addError('roleIds', $exception->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', $message);
    }

    public function confirm(ManagePlatformUsers $users): void
    {
        $actor = $this->requirePlatformPermission('platform.user.manage');
        [$kind, $uuid] = array_pad(explode(':', (string) $this->panel, 2), 2, '');
        if (in_array($kind, ['block', 'unblock', 'archive', 'restore', 'mfa'], true)) {
            $this->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => __('sadmin_users.fields.reason')]);
        }
        $user = $this->target($uuid);

        try {
            match ($kind) {
                'block' => $users->setActive($user, false, $actor, $this->reason),
                'unblock' => $users->setActive($user, true, $actor, $this->reason),
                'archive' => $users->archive($user, $actor, $this->reason),
                'restore' => $users->restore($user, $actor, $this->reason),
                'mfa' => $users->resetMfa($user, $actor, $this->reason),
                'link' => $users->sendPasswordLink($user, $actor),
                default => abort(404),
            };
        } catch (DomainException $exception) {
            $this->addError('reason', $exception->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('notice', __('sadmin_users.saved.'.$kind));
    }

    public function render(PlatformPreferences $preferences): mixed
    {
        $actor = $this->requirePlatformPermission('platform.user.manage');

        $users = PlatformUser::query()
            ->with('roles')
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $inner) => $inner
                ->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')))
            ->when($this->show === 'active', fn (Builder $q) => $q->where('is_active', true)->whereNull('archived_at'))
            ->when($this->show === 'blocked', fn (Builder $q) => $q->where('is_active', false)->whereNull('archived_at'))
            ->when($this->show === 'archived', fn (Builder $q) => $q->whereNotNull('archived_at'))
            ->when($this->role !== '' && ctype_digit($this->role), fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('platform_roles.id', (int) $this->role)))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.sadmin.users.index', [
            'users' => $users,
            // Platform staff sign in with their email, and whoever manages
            // them needs it in full. These are Meta Style's own people, not a
            // center's customers, so no masking applies.
            'emails' => $users->getCollection()->mapWithKeys(fn (PlatformUser $user): array => [$user->uuid => $user->email])->all(),
            'actor' => $actor,
            'roles' => PlatformRole::query()->whereNull('archived_at')->orderByDesc('is_system')->orderBy('key')->get(),
            'counts' => [
                'active' => PlatformUser::query()->where('is_active', true)->whereNull('archived_at')->count(),
                'blocked' => PlatformUser::query()->where('is_active', false)->whereNull('archived_at')->count(),
                'archived' => PlatformUser::query()->whereNotNull('archived_at')->count(),
            ],
            'mfaRequired' => $preferences->mfaRequired(),
            'target' => $this->panel !== null && str_contains($this->panel, ':') ? PlatformUser::query()->where('uuid', explode(':', $this->panel, 2)[1])->first() : null,
        ]);
    }

    private function target(string $uuid): PlatformUser
    {
        /** @var PlatformUser $user */
        $user = PlatformUser::query()->where('uuid', $uuid)->firstOrFail();

        return $user;
    }
}
