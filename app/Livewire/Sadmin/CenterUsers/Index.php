<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\CenterUsers;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Directory\CenterUserBranch;
use App\Kernel\Platform\Directory\CenterUserDirectory;
use App\Kernel\Platform\Directory\CenterUserEntry;
use App\Kernel\Platform\Directory\CenterUserSearch;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\SaasAdmin\Application\Actions\ManageCenterAccount;
use App\Modules\SaasAdmin\Application\Actions\UpdateCenterUserIdentity;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Super Admin → Centers → Center users: every owner, manager and staff account
 * across all centers, from the control-plane directory (never a tenant query
 * per page view). Platform users are a different page and a different thing.
 *
 * Viewing needs `platform.center_user.view`; changing anything needs
 * `platform.center_user.manage`, and every change goes to the center first,
 * through an audited action, then re-projects that center. No impersonation.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    use AuthorizesPlatform;
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $center = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phone = '';

    #[Url(except: '')]
    public string $created = '';

    #[Url(except: 'name')]
    public string $sort = 'name';

    /** The open account, as `tenant:uuid`. */
    #[Url(except: '')]
    public string $open = '';

    /** `edit`, `block`, `reactivate`, `link`. */
    public ?string $panel = null;

    public string $editName = '';

    public string $editEmail = '';

    public string $editPhone = '';

    public string $editPhoneCountry = PhoneCountries::DEFAULT;

    public string $reason = '';

    public function mount(): void
    {
        $this->requirePlatformPermission('platform.center_user.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'center', 'kind', 'role', 'branch', 'status', 'phone', 'created', 'sort'], true)) {
            if ($property === 'center') {
                $this->branch = '';
            }
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'center', 'kind', 'role', 'branch', 'status', 'phone', 'created');
        $this->resetPage();
    }

    public function show(int $entryId): void
    {
        $this->requirePlatformPermission('platform.center_user.view');
        $entry = CenterUserEntry::query()->find($entryId);
        $this->open = $entry instanceof CenterUserEntry ? $entry->tenant_id.':'.$entry->user_uuid : '';
        $this->closePanel();
    }

    public function closeDetail(): void
    {
        $this->open = '';
        $this->closePanel();
    }

    public function openPanel(string $panel): void
    {
        $this->requirePlatformPermission('platform.center_user.manage');
        $entry = $this->selected();
        if (! $entry instanceof CenterUserEntry || ! in_array($panel, ['edit', 'block', 'reactivate', 'link'], true)) {
            return;
        }
        $this->closePanel();
        $this->panel = $panel;
        if ($panel === 'edit') {
            $this->editName = $entry->name;
            $this->editEmail = (string) $entry->email;
            $this->editPhoneCountry = $entry->phone_country ?? PhoneCountries::DEFAULT;
            $this->editPhone = (string) $entry->phone_national;
        }
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->reset('editName', 'editEmail', 'editPhone', 'editPhoneCountry', 'reason');
        $this->resetValidation();
    }

    public function saveIdentity(UpdateCenterUserIdentity $update): void
    {
        $user = $this->requirePlatformPermission('platform.center_user.manage');
        [$tenant, $entry] = $this->target();
        $this->validate([
            'editName' => ['required', 'string', 'max:190'],
            'editEmail' => ['nullable', 'email:rfc', 'max:190'],
            'editPhoneCountry' => ['required', 'string', 'size:2'],
            'editPhone' => ['required', 'string', 'max:32', new PhoneRule($this->editPhoneCountry)],
        ], [], [
            'editName' => __('sadmin_center_users.fields.name'),
            'editEmail' => __('sadmin_center_users.fields.email'),
            'editPhone' => __('phone_field.label'),
        ]);
        $phone = PhoneNumber::fromParts($this->editPhoneCountry, $this->editPhone);
        if (! $phone instanceof PhoneNumber) {
            $this->addError('editPhone', __('phone_field.errors.invalid'));

            return;
        }

        try {
            $update($tenant, $entry->user_uuid, $this->editName, $this->editEmail !== '' ? $this->editEmail : null, $phone, Actor::platform($user));
        } catch (DomainException $exception) {
            $this->addError('editPhone', $exception->getMessage());

            return;
        }
        $this->closePanel();
        session()->flash('notice', __('sadmin_center_users.saved'));
    }

    public function setActive(bool $active, ManageCenterAccount $accounts, CenterUserDirectory $directory): void
    {
        $user = $this->requirePlatformPermission('platform.center_user.manage');
        [$tenant, $entry] = $this->target();
        $this->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']], [], ['reason' => __('sadmin_center_users.fields.reason')]);

        try {
            $accounts->setActive($tenant, $entry->user_uuid, $active, Actor::platform($user), $this->reason);
        } catch (DomainException $exception) {
            $this->addError('reason', $exception->getMessage());

            return;
        }
        $directory->refreshTenant($tenant);
        $this->closePanel();
        session()->flash('notice', $active ? __('sadmin_center_users.reactivated') : __('sadmin_center_users.blocked'));
    }

    public function sendLink(ManageCenterAccount $accounts): void
    {
        $user = $this->requirePlatformPermission('platform.center_user.manage');
        [$tenant, $entry] = $this->target();

        try {
            $accounts->sendAccessLink($tenant, $entry->user_uuid, Actor::platform($user));
        } catch (DomainException $exception) {
            $this->closePanel();
            session()->flash('notice-error', $exception->getMessage());

            return;
        }
        $this->closePanel();
        session()->flash('notice', __('sadmin_center_users.link_sent'));
    }

    public function refreshDirectory(CenterUserDirectory $directory): void
    {
        $this->requirePlatformPermission('platform.center_user.view');
        if ($this->center !== '' && ($tenant = TenantModel::query()->find($this->center)) instanceof TenantModel) {
            $directory->refreshTenant($tenant);
        } else {
            $directory->refreshAll();
        }
        session()->flash('notice', __('sadmin_center_users.refreshed'));
    }

    public function render(CenterUserSearch $search): mixed
    {
        $viewer = $this->requirePlatformPermission('platform.center_user.view');
        $filters = [
            'search' => $this->search,
            'center' => $this->center,
            'kind' => $this->kind,
            'role' => $this->role,
            'branch' => ctype_digit($this->branch) ? (int) $this->branch : null,
            'status' => $this->status,
            'phone' => $this->phone,
            'created' => $this->created,
            'sort' => in_array($this->sort, CenterUserSearch::SORTS, true) ? $this->sort : 'name',
        ];
        $locale = app()->getLocale();
        $entries = $search->query($filters)->with(['tenant:id,name,slug', 'branches'])->paginate(25)
            ->through(fn (CenterUserEntry $entry): array => $this->present($entry, $locale));
        $selected = $this->selected();

        return view('livewire.sadmin.center-users.index', [
            'entries' => $entries,
            'centers' => TenantModel::query()->orderBy('name')->get(['id', 'name', 'slug']),
            'roles' => CenterUserEntry::query()->whereNotNull('role_key')->distinct()->orderBy('role_key')->pluck('role_key')->all(),
            'branches' => $this->center !== ''
                ? CenterUserBranch::query()->where('tenant_id', $this->center)->get(['branch_id', 'branch_name'])->unique('branch_id')->values()
                : collect(),
            'missingPhones' => CenterUserEntry::query()->whereNull('phone_e164')->count(),
            'total' => CenterUserEntry::query()->count(),
            'lastProjected' => CenterUserEntry::query()->max('projected_at'),
            'activeFilters' => count(array_filter([$this->center, $this->kind, $this->role, $this->branch, $this->status, $this->phone, $this->created])),
            'selected' => $selected instanceof CenterUserEntry ? $this->present($selected, $locale) : null,
            'audit' => $selected instanceof CenterUserEntry
                ? PlatformAuditLog::query()->where('tenant_id', $selected->tenant_id)->where('target_type', User::class)->where('target_id', $selected->user_uuid)->latest('occurred_at')->limit(8)->get()
                : collect(),
            'canManage' => $viewer->hasPermission('platform.center_user.manage'),
            'canCenter' => $viewer->hasPermission('platform.center.view'),
        ])->title(__('sadmin_center_users.title'));
    }

    /**
     * Plain display values for one account — the template never reaches for
     * a contact column itself (CustomerPrivacyTest).
     *
     * @return array<string, mixed>
     */
    private function present(CenterUserEntry $entry, string $locale): array
    {
        $pick = static fn (mixed $value): string => is_array($value) ? (string) ($value[$locale] ?? $value['en'] ?? (reset($value) ?: '')) : (string) $value;
        $role = null;
        foreach ($entry->roles ?? [] as $candidate) {
            if ($candidate['key'] === $entry->role_key) {
                $role = $pick($candidate['name']);
            }
        }
        $country = $entry->phone_country;

        return [
            'id' => $entry->id,
            'tenant_id' => $entry->tenant_id,
            'name' => $entry->name,
            'contact_email' => $entry->email,
            'contact_phone' => $entry->phone_e164 !== null ? (PhoneNumber::parse($entry->phone_e164)?->international() ?? $entry->phone_e164) : null,
            'phone_country' => $country,
            'phone_country_name' => $country !== null ? PhoneCountries::name($country, $locale) : null,
            'flag' => $country !== null ? PhoneCountries::flag($country) : null,
            'center' => $entry->tenant?->name,
            'slug' => $entry->tenant?->slug,
            'kind' => $entry->kind,
            'role' => $role ?? __('sadmin_center_users.kinds.'.$entry->kind),
            'branches' => $entry->all_branches ? __('sadmin_center_users.all_branches') : ($entry->branches->isEmpty() ? '—' : $entry->branches->map(fn (CenterUserBranch $branch): string => $pick($branch->branch_name ?? []))->filter()->implode(', ')),
            'active' => $entry->is_active,
            'owner' => $entry->is_owner,
            'created' => $entry->account_created_at,
            'last_login' => $entry->last_login_at,
            'projected' => $entry->projected_at,
        ];
    }

    private function selected(): ?CenterUserEntry
    {
        if (! str_contains($this->open, ':')) {
            return null;
        }
        [$tenantId, $uuid] = explode(':', $this->open, 2);

        return CenterUserEntry::query()->with(['tenant:id,name,slug', 'branches'])->where('tenant_id', $tenantId)->where('user_uuid', $uuid)->first();
    }

    /** @return array{0: TenantModel, 1: CenterUserEntry} */
    private function target(): array
    {
        $entry = $this->selected();
        // The full tenant row: running in its context needs more than the
        // name and slug the list loads.
        $tenant = $entry instanceof CenterUserEntry ? TenantModel::query()->find($entry->tenant_id) : null;
        abort_unless($entry instanceof CenterUserEntry && $tenant instanceof TenantModel, 404);

        return [$tenant, $entry];
    }
}
