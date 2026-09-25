<?php

declare(strict_types=1);

namespace App\Livewire\Center\Staff;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Employees\Application\Actions\SetEmployeeStatus;
use App\Modules\Employees\Application\Actions\UpdateEmployee;
use App\Modules\Employees\Application\EmployeePresenter;
use App\Modules\Employees\Application\EmployeeQuery;
use App\Modules\Employees\Domain\Data\EmployeeChanges;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * One member of staff, in a drawer over the team list.
 *
 * Details and lifecycle live here; access (roles, branch scope, the login and
 * its activation link), services and time off are child components so each
 * stays small and re-checks its own permission. The person is looked up
 * through the branch-scoped {@see EmployeeQuery} on every request: a uuid from
 * the browser is a request, not a grant.
 */
final class Profile extends Component
{
    #[Locked]
    public string $uuid = '';

    public string $tab = 'overview';

    public bool $editing = false;

    /** @var array<string, string> */
    public array $name = [];

    /** @var array<int, int|string> checkbox values arrive as strings */
    public array $branchIds = [];

    /** `deactivate` or `reactivate` while the confirmation is open. */
    public ?string $confirm = null;

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function close(): void
    {
        $this->dispatch('staff-profile-closed');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['overview', 'access', 'services', 'time_off'], true) ? $tab : 'overview';
        $this->editing = false;
        $this->notice = '';
    }

    public function startEdit(): void
    {
        $employee = $this->employee();

        if (! $employee instanceof Employee) {
            return;
        }

        $this->name = $employee->name->all();
        $this->branchIds = $employee->branches->map(static fn ($branch): int => (int) $branch->id)->values()->all();
        $this->resetValidation();
        $this->editing = true;
        $this->notice = '';
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(UpdateEmployee $update, TenantLocales $locales): void
    {
        $employee = $this->employee();

        if (! $employee instanceof Employee) {
            return;
        }

        $this->validate([
            'name.'.$locales->default() => ['required', 'string', 'min:2', 'max:190'],
            'name.*' => ['nullable', 'string', 'max:190'],
            'branchIds' => ['array'],
            'branchIds.*' => ['integer'],
        ], [], StaffOptions::attributes());

        try {
            $update($employee, new EmployeeChanges($this->name, array_values(array_map('intval', $this->branchIds))), $this->actor());
        } catch (ValidationException $e) {
            StaffOptions::remapErrors($this, $e);

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->editing = false;
        $this->flash(__('manager_staff.profile.saved'));
        $this->dispatch('staff-updated', message: '');
    }

    public function askStatus(string $kind): void
    {
        $this->confirm = in_array($kind, ['deactivate', 'reactivate'], true) ? $kind : null;
        $this->resetValidation('confirm');
    }

    public function closeConfirm(): void
    {
        $this->confirm = null;
    }

    public function setStatus(SetEmployeeStatus $setStatus): void
    {
        $employee = $this->employee();

        if (! $employee instanceof Employee || $this->confirm === null) {
            return;
        }

        $status = $this->confirm === 'reactivate' ? EmployeeStatus::Active : EmployeeStatus::Inactive;

        try {
            $setStatus($employee, $status, $this->actor());
        } catch (AuthorizationException $e) {
            $this->addError('confirm', $e->getMessage());

            return;
        }

        $this->confirm = null;
        $this->flash($status->isActive() ? __('manager_staff.profile.reactivated') : __('manager_staff.profile.deactivated'));
        $this->dispatch('staff-updated', message: '');
    }

    #[On('staff-access-changed')]
    public function accessChanged(string $message = ''): void
    {
        if ($message !== '') {
            $this->flash($message);
        }
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(): mixed
    {
        $viewer = $this->actor();
        $employee = $viewer->hasPermission(Permission::StaffView) ? $this->employee() : null;
        $locales = app(TenantLocales::class);

        return view('livewire.center.staff.profile', [
            'person' => $employee instanceof Employee ? EmployeePresenter::for($viewer)->detail($employee) : null,
            // Live branches, plus an archived one the person is still assigned
            // to — otherwise saving the form would silently drop it.
            'branchOptions' => $this->editing
                ? array_values(array_filter(
                    StaffOptions::branches($viewer, includeArchived: true),
                    fn (array $option): bool => ! $option['archived'] || in_array($option['id'], array_map('intval', $this->branchIds), true),
                ))
                : [],
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
        ]);
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function employee(): ?Employee
    {
        return app(EmployeeQuery::class)->find($this->uuid, $this->actor());
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
