<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Creates the LOGIN half of a member of staff.
 *
 * Shared by adding a person with a login ({@see Actions\CreateEmployee}) and
 * by giving an existing person one later ({@see Actions\GrantEmployeeLogin}),
 * so both apply the same identity rules:
 *
 *  - every center account has a valid phone, unique in this center;
 *  - an email is optional, lower-cased, and unique in this center (the column
 *    is UNIQUE, so a duplicate must be a field error, never a 500);
 *  - roles are granted only when every permission they carry is held by the
 *    person granting them;
 *  - no password — the account waits for its activation link.
 */
final class StaffAccountFactory
{
    /**
     * @throws ValidationException
     */
    public function requirePhone(?string $phone, ?User $ignore = null): PhoneNumber
    {
        $parsed = PhoneNumber::parse($phone);

        if (! $parsed instanceof PhoneNumber) {
            throw ValidationException::withMessages(['phone' => __('phone_field.errors.login_needs_phone')]);
        }

        if (User::query()->where('phone', $parsed->e164)->when($ignore instanceof User, fn ($query) => $query->whereKeyNot($ignore?->getKey()))->exists()) {
            throw ValidationException::withMessages(['phone' => __('phone_field.errors.taken')]);
        }

        return $parsed;
    }

    /**
     * @throws ValidationException
     */
    public function normaliseEmail(?string $email, ?User $ignore = null): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return null;
        }

        if (User::query()->where('email', $email)->when($ignore instanceof User, fn ($query) => $query->whereKeyNot($ignore?->getKey()))->exists()) {
            throw ValidationException::withMessages(['email' => __('manager_staff.errors.email_taken')]);
        }

        return $email;
    }

    /**
     * Nobody may grant a permission they do not hold themselves.
     *
     * @param  list<int>  $roleIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function assertCanGrantRoles(array $roleIds, User $actingUser): void
    {
        if ($roleIds === []) {
            return;
        }

        $roles = Role::query()->whereIn('id', $roleIds)->get();

        if ($roles->count() !== count(array_unique($roleIds))) {
            throw ValidationException::withMessages(['roles' => __('manager_staff.errors.role_unknown')]);
        }

        $held = $actingUser->permissions();

        foreach ($roles as $role) {
            if (array_diff($role->permissionCodes(), $held) !== []) {
                throw new AuthorizationException(__('manager_staff.errors.role_escalation'));
            }
        }
    }

    /**
     * @param  list<int>  $branchIds
     * @param  list<int>  $roleIds
     */
    public function create(string $name, PhoneNumber $phone, ?string $email, array $branchIds, array $roleIds): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'phone' => $phone->e164,
            // No password: the account exists but cannot sign in until the
            // person redeems their activation link.
            'password' => null,
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => false,
        ]);

        $user->syncBranchScope($branchIds);

        if ($roleIds !== []) {
            $user->roles()->sync($roleIds);
        }

        return $user;
    }
}
