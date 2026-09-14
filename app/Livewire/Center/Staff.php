<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Application\Actions\SetEmployeeStatus;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Staff management.
 *
 * The component gathers input and shows results; every decision about who may
 * do what lives in the Actions it calls, so the same rules apply to the API and
 * to any later caller (docs/13-ROADMAP.md Phase 3 Part I).
 */
#[Layout('components.layouts.app')]
final class Staff extends Component
{
    public string $name = '';

    public string $email = '';

    /** @var list<int> */
    public array $branchIds = [];

    /** @var list<int> */
    public array $roleIds = [];

    /**
     * Shown once, for the manager to hand over. Never stored in plaintext and
     * never sent anywhere by Meta Style in Phase 3.
     */
    public ?string $activationToken = null;

    public function create(CreateEmployee $createEmployee): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'branchIds' => ['array'],
            'roleIds' => ['array'],
        ]);

        try {
            $result = $createEmployee(
                new NewEmployee(
                    name: [app()->getLocale() => $validated['name']],
                    branchIds: array_map('intval', $this->branchIds),
                    roleIds: array_map('intval', $this->roleIds),
                    email: $validated['email'] !== '' ? $validated['email'] : null,
                ),
                $this->actor(),
            );
        } catch (AuthorizationException $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->activationToken = $result['activation_token'];
        $this->reset('name', 'email', 'branchIds', 'roleIds');
    }

    public function deactivate(int $employeeId, SetEmployeeStatus $setStatus): void
    {
        $employee = Employee::query()->with('user', 'branches')->findOrFail($employeeId);

        try {
            $setStatus($employee, EmployeeStatus::Inactive, $this->actor());
        } catch (AuthorizationException $e) {
            $this->addError('name', $e->getMessage());
        }
    }

    public function render(): mixed
    {
        $user = $this->actor();

        return view('livewire.center.staff', [
            'canCreate' => $user->hasPermission(Permission::StaffCreate),
            'canDeactivate' => $user->hasPermission(Permission::StaffDeactivate),
            'employees' => $user->hasPermission(Permission::StaffView)
                ? Employee::query()->with('branches', 'user')->orderBy('id')->get()
                : collect(),
            'branches' => Branch::query()->orderBy('id')->get(),
            'roles' => Role::query()->orderBy('id')->get(),
        ]);
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
