<?php

declare(strict_types=1);

namespace App\Livewire\Center\Staff;

use App\Kernel\Authorization\Actions\AssignRolesToUser;
use App\Kernel\Authorization\Actions\SetUserBranchScope;
use App\Kernel\Authorization\Actions\StaffAccessRules;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Application\Actions\GrantEmployeeLogin;
use App\Modules\Employees\Application\Actions\ReissueActivationLink;
use App\Modules\Employees\Application\Actions\UpdateEmployeeLogin;
use App\Modules\Employees\Application\EmployeePresenter;
use App\Modules\Employees\Application\EmployeeQuery;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A member of staff's ACCESS: their login, its activation link, their roles
 * and the branches the login may act in.
 *
 * Every change is a security change, made by its own audited Action with the
 * no-escalation rules (grant only what you hold, never the owner, never
 * yourself, never someone who outranks you). This component only draws what
 * those Actions would allow and reports what they refuse.
 */
final class Access extends Component
{
    #[Locked]
    public string $uuid = '';

    /** @var array<int, int|string> checkbox values arrive as strings */
    public array $roleIds = [];

    /** `all` or `some` — a string, so the radio pair binds without coercion. */
    public string $scopeMode = 'some';

    /** @var array<int, int|string> */
    public array $scopeIds = [];

    public string $phone = '';

    public string $phoneCountry = PhoneCountries::DEFAULT;

    public string $email = '';

    /** The pending login's contact, corrected before its link is used. */
    public string $contactPhone = '';

    public string $contactPhoneCountry = PhoneCountries::DEFAULT;

    public string $contactEmail = '';

    /** Shown once after issuing; only its hash is stored. */
    public ?string $activationToken = null;

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->load();
    }

    public function saveRoles(AssignRolesToUser $assign): void
    {
        [, $user] = $this->target();

        try {
            $assign($user, array_values(array_map('intval', $this->roleIds)), $this->actor());
        } catch (AuthorizationException|ValidationException $e) {
            $this->addError('roleIds', $this->messageOf($e));

            return;
        }

        $this->changed(__('manager_staff.access.roles_saved'));
    }

    public function saveScope(SetUserBranchScope $setScope): void
    {
        [, $user] = $this->target();

        try {
            $setScope($user, $this->scopeMode === 'all', array_values(array_map('intval', $this->scopeIds)), $this->actor());
        } catch (AuthorizationException|ValidationException $e) {
            $this->addError('scopeIds', $this->messageOf($e));

            return;
        }

        $this->changed(__('manager_staff.access.scope_saved'));
    }

    public function reissue(ReissueActivationLink $reissue): void
    {
        [$employee] = $this->target();

        try {
            $this->activationToken = $reissue($employee, $this->actor());
        } catch (AuthorizationException|ValidationException $e) {
            $this->addError('login', $this->messageOf($e));
        }
    }

    public function grantLogin(GrantEmployeeLogin $grant): void
    {
        $employee = $this->employee();

        if (! $employee instanceof Employee) {
            return;
        }

        $this->validate([
            'phoneCountry' => ['required', 'string', 'size:2'],
            'phone' => ['required', 'string', 'max:32', new PhoneRule($this->phoneCountry)],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'roleIds' => ['array'],
            'roleIds.*' => ['integer'],
        ], [], StaffOptions::attributes());

        try {
            $result = $grant(
                $employee,
                PhoneNumber::fromParts($this->phoneCountry, $this->phone)?->e164,
                trim($this->email) !== '' ? trim($this->email) : null,
                array_values(array_map('intval', $this->roleIds)),
                $this->actor(),
            );
        } catch (ValidationException $e) {
            StaffOptions::remapErrors($this, $e);

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->activationToken = $result['activation_token'];
        $this->reset('phone', 'phoneCountry', 'email');
        $this->load();
        $this->dispatch('staff-updated', message: '');
    }

    public function saveContact(UpdateEmployeeLogin $update): void
    {
        [$employee] = $this->target();

        $this->validate([
            'contactPhoneCountry' => ['required', 'string', 'size:2'],
            'contactPhone' => ['required', 'string', 'max:32', new PhoneRule($this->contactPhoneCountry)],
            'contactEmail' => ['nullable', 'email:rfc', 'max:190'],
        ], [], [
            'contactPhone' => __('phone_field.label'),
            'contactPhoneCountry' => __('phone_field.country'),
            'contactEmail' => __('manager_staff.fields.email'),
        ]);

        try {
            $update(
                $employee,
                PhoneNumber::fromParts($this->contactPhoneCountry, $this->contactPhone)?->e164,
                trim($this->contactEmail) !== '' ? trim($this->contactEmail) : null,
                $this->actor(),
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError(['phone' => 'contactPhone', 'email' => 'contactEmail'][$field] ?? 'login', (string) ($messages[0] ?? ''));
            }

            return;
        } catch (AuthorizationException $e) {
            $this->addError('login', $e->getMessage());

            return;
        }

        $this->changed(__('manager_staff.access.contact_saved'));
    }

    public function dismissToken(): void
    {
        $this->activationToken = null;
    }

    public function render(): mixed
    {
        $viewer = $this->actor();
        $employee = $this->employee();
        $person = $employee instanceof Employee ? EmployeePresenter::for($viewer)->detail($employee) : null;

        return view('livewire.center.staff.access', [
            'person' => $person,
            'roleOptions' => $person !== null ? $this->roleOptions($viewer, $person['role_ids']) : [],
            // Live branches, plus an archived one the login still reaches — it
            // can be kept or unticked, but never silently lost or unshown.
            'branchOptions' => array_values(array_filter(
                StaffOptions::branches($viewer, includeArchived: true),
                fn (array $option): bool => ! $option['archived'] || in_array($option['id'], array_map('intval', $this->scopeIds), true),
            )),
            'viewerUnrestricted' => $viewer->branchScope()->isUnrestricted(),
            'activationUrl' => $this->activationToken !== null ? route('activate', ['token' => $this->activationToken]) : null,
        ]);
    }

    /**
     * Grantable roles plus any the person already holds — every one of which
     * the viewer holds too, or the panel would be read-only — so saving never
     * silently drops a role that was not on offer.
     *
     * @param  list<int>  $held
     * @return list<array{id: int, name: string, system: bool, count: int}>
     */
    private function roleOptions(User $viewer, array $held): array
    {
        $options = StaffOptions::grantableRoles($viewer);
        $known = array_column($options, 'id');

        foreach (Role::query()->with('permissions')->whereIn('id', array_diff($held, $known))->get() as $role) {
            $options[] = ['id' => (int) $role->id, 'uuid' => $role->uuid, 'name' => $role->name->get(), 'system' => $role->is_system, 'count' => $role->permissions->count()];
        }

        return array_map(static fn (array $option): array => [
            'id' => $option['id'], 'name' => $option['name'], 'system' => $option['system'], 'count' => $option['count'],
        ], $options);
    }

    private function load(): void
    {
        $employee = $this->employee();
        $user = $employee?->user;

        if (! $user instanceof User) {
            return;
        }

        $this->roleIds = $user->roles->map(static fn (Role $role): int => (int) $role->id)->values()->all();
        $this->scopeMode = $user->all_branches ? 'all' : 'some';
        $this->scopeIds = StaffAccessRules::storedBranchIds($user);

        // Only a login nobody has activated yet may have its contact corrected.
        if ($user->password === null) {
            $parsed = PhoneNumber::parse($user->phone);
            $country = $parsed?->country();

            [$this->contactPhoneCountry, $this->contactPhone] = $parsed instanceof PhoneNumber && $country !== null
                ? [$country, $parsed->national()]
                : [PhoneCountries::DEFAULT, (string) $user->phone];
            $this->contactEmail = (string) $user->email;
        }
    }

    private function changed(string $message): void
    {
        $this->resetValidation();
        $this->load();
        $this->dispatch('staff-access-changed', message: $message);
        $this->dispatch('staff-updated', message: '');
    }

    /**
     * @return array{0: Employee, 1: User}
     */
    private function target(): array
    {
        $employee = $this->employee();
        $user = $employee?->user;

        abort_unless($employee instanceof Employee && $user instanceof User, 404);

        return [$employee, $user];
    }

    private function employee(): ?Employee
    {
        return app(EmployeeQuery::class)->find($this->uuid, $this->actor());
    }

    private function messageOf(AuthorizationException|ValidationException $e): string
    {
        return $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
