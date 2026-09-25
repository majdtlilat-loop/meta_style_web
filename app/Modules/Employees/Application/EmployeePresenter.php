<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Kernel\Authorization\Actions\StaffAccessRules;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Domain\Models\Employee;

/**
 * Plain display values for one member of staff.
 *
 * The template never reaches for a contact column (CustomerPrivacyTest): the
 * email and phone arrive here as neutral `contact_*` keys, the phone already
 * in international form. No password, hash or token ever leaves this class.
 *
 * The `can` flags are computed from the SAME rules the Actions enforce
 * (StaffGuard, the owner and self protections), so a button is drawn only
 * when pressing it can succeed. They are presentation; the Actions still
 * decide.
 */
final class EmployeePresenter
{
    /** @var list<string> */
    private array $held;

    private function __construct(private readonly User $viewer)
    {
        $this->held = $viewer->permissions();
    }

    public static function for(User $viewer): self
    {
        return new self($viewer);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Employee $employee): array
    {
        $user = $employee->user;
        $name = $employee->name->get();
        $login = self::loginState($user);
        $isOwner = $user instanceof User && $user->is_owner;
        $isSelf = $user instanceof User && (int) $user->getKey() === (int) $this->viewer->getKey();
        $active = $employee->status->isActive();
        $reaches = StaffGuard::reaches($this->viewer, $employee);
        $outranks = $user instanceof User && ! $isSelf && StaffGuard::outranks($user, $this->viewer);
        $phone = $user instanceof User ? PhoneNumber::parse($user->phone) : null;
        $country = $phone?->country();

        return [
            'uuid' => $employee->uuid,
            'name' => $name,
            'initial' => mb_strtoupper(mb_substr($name, 0, 1)),
            'status' => $employee->status->value,
            'active' => $active,
            'login' => $login,
            'login_tone' => ['none' => 'neutral', 'pending' => 'warning', 'active' => 'success', 'disabled' => 'danger'][$login],
            'contact_email' => $user instanceof User ? $user->email : null,
            'contact_phone' => $phone?->international(),
            'phone_flag' => $country !== null ? PhoneCountries::flag($country) : null,
            'branches' => $employee->branches
                ->map(static fn (Branch $branch): string => $branch->name->get())
                ->values()->all(),
            'roles' => $user instanceof User
                ? $user->roles->map(static fn (Role $role): string => $role->name->get())->values()->all()
                : [],
            'is_owner' => $isOwner,
            'is_self' => $isSelf,
            'can' => [
                'edit' => $this->holds(Permission::StaffUpdate) && $reaches,
                'services' => $this->holds(Permission::StaffUpdate) && $reaches,
                'deactivate' => $active && ! $isOwner && ! $isSelf && ! $outranks
                    && $this->holds(Permission::StaffDeactivate) && $reaches,
                'reactivate' => ! $active && ! $isOwner && ! $isSelf && ! $outranks
                    && $this->holds(Permission::StaffDeactivate) && $reaches,
                'access' => $user instanceof User && ! $isOwner && ! $isSelf && ! $outranks
                    && $this->holds(Permission::StaffAccessManage) && $reaches,
                'reissue' => $login === 'pending' && $active && ! $isOwner && ! $isSelf && ! $outranks
                    && $this->holds(Permission::StaffAccessManage) && $reaches,
                // Contact corrections only before activation (UpdateEmployeeLogin).
                'contact' => $login === 'pending' && ! $isOwner && ! $isSelf && ! $outranks
                    && $this->holds(Permission::StaffAccessManage) && $reaches,
                'grant_login' => $login === 'none' && $active
                    && $this->holds(Permission::StaffAccessManage) && $reaches,
                'time_off' => $this->holds(Permission::AvailabilityBlockManage),
            ],
        ];
    }

    /**
     * Everything the profile drawer shows and edits.
     *
     * @return array<string, mixed>
     */
    public function detail(Employee $employee): array
    {
        $user = $employee->user;

        return [
            ...$this->summary($employee),
            'names' => $employee->name->all(),
            'branch_ids' => $employee->branches->map(static fn (Branch $branch): int => (int) $branch->id)->values()->all(),
            'role_ids' => $user instanceof User
                ? $user->roles->map(static fn (Role $role): int => (int) $role->id)->values()->all()
                : [],
            'all_branches' => $user instanceof User && $user->all_branches,
            'scope_ids' => $user instanceof User ? StaffAccessRules::storedBranchIds($user) : [],
            'last_login_at' => $user?->last_login_at,
            'created_at' => $employee->created_at,
        ];
    }

    /**
     * none — listed only, no account; pending — account created, link not yet
     * used; active — can sign in; disabled — account switched off.
     */
    public static function loginState(?User $user): string
    {
        return match (true) {
            ! $user instanceof User => 'none',
            ! $user->is_active => 'disabled',
            $user->password === null => 'pending',
            default => 'active',
        };
    }

    private function holds(Permission $permission): bool
    {
        return $this->viewer->is_active && in_array($permission->value, $this->held, true);
    }
}
