<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Platform\Identity\Mail\PlatformAccessEmail;
use App\Kernel\Platform\Identity\Models\PlatformRole;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Identity\PlatformMfa;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The people who operate Meta Style itself.
 *
 * Invitations never carry a password: a new user is created with a random,
 * never-shown credential and emailed a one-time link (the `platform` password
 * broker) to choose their own. Guards that keep the platform operable:
 *
 *  - nobody may block, archive or strip themselves;
 *  - the last active user who can manage platform users cannot be removed;
 *  - nobody may assign a role carrying a permission they do not hold.
 *
 * Archive instead of delete: support tickets, audit entries and billing
 * records keep resolving to a name.
 */
final class ManagePlatformUsers
{
    public function __construct(private readonly Audit $audit, private readonly PlatformMfa $mfa) {}

    /** @param list<int> $roleIds */
    public function invite(string $name, string $email, array $roleIds, PlatformUser $actor): PlatformUser
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(__('sadmin_users.errors.identity'));
        }
        if (PlatformUser::query()->where('email', $email)->exists()) {
            throw new DomainException(__('sadmin_users.errors.email_taken'));
        }
        $roles = $this->assignableRoles($roleIds, $actor);

        /** @var PlatformUser $user */
        $user = DB::connection('control')->transaction(function () use ($name, $email, $roles): PlatformUser {
            /** @var PlatformUser $created */
            $created = PlatformUser::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'mfa_secret' => $this->mfa->generateSecret(),
            ]);
            $created->roles()->sync($roles);

            return $created;
        });

        $this->sendAccessLink($user, 'invite');

        $this->audit->record(new AuditEvent(
            action: 'platform.user.invited',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: AuditSeverity::Notice,
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            after: ['roles' => $this->roleKeys($roles)],
        ));

        // Re-read so columns the database defaulted (archived_at, …) are present.
        return $user->refresh();
    }

    /** @param list<int> $roleIds */
    public function update(PlatformUser $user, string $name, string $email, array $roleIds, PlatformUser $actor): PlatformUser
    {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        if ($name === '' || mb_strlen($name) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(__('sadmin_users.errors.identity'));
        }
        if (PlatformUser::query()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            throw new DomainException(__('sadmin_users.errors.email_taken'));
        }

        $currentRoles = array_map('intval', $user->roles()->pluck('platform_roles.id')->all());
        $roles = array_map('intval', $roleIds);
        sort($currentRoles);
        sort($roles);
        $rolesChanged = $currentRoles !== $roles;
        if ($rolesChanged) {
            // Only the roles being ADDED need to be within the actor's reach;
            // keeping a role someone already has is not a grant.
            $this->assignableRoles(array_values(array_diff($roles, $currentRoles)), $actor);
            if ($user->is($actor)) {
                throw new DomainException(__('sadmin_users.errors.self_roles'));
            }
        }

        $before = ['name' => $user->name, 'email_changed' => false, 'roles' => $this->roleKeys($currentRoles)];
        DB::connection('control')->transaction(function () use ($user, $name, $email, $roles, $rolesChanged): void {
            $user->forceFill(['name' => $name, 'email' => $email])->save();
            if ($rolesChanged) {
                $user->roles()->sync($roles);
                $user->forgetPermissionCache();
                $this->assertStillManageable();
            }
        });

        $this->audit->record(new AuditEvent(
            action: 'platform.user.updated',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: $rolesChanged ? AuditSeverity::Warning : AuditSeverity::Notice,
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            before: $before,
            after: ['name' => $name, 'email_changed' => $user->wasChanged('email'), 'roles' => $this->roleKeys($roles)],
        ));

        return $user->refresh();
    }

    public function setActive(PlatformUser $user, bool $active, PlatformUser $actor, string $reason): void
    {
        $reason = $this->reason($reason);
        if ($user->is($actor)) {
            throw new DomainException(__('sadmin_users.errors.self'));
        }
        if ($user->archived_at !== null && $active) {
            throw new DomainException(__('sadmin_users.errors.archived'));
        }

        DB::connection('control')->transaction(function () use ($user, $active): void {
            $user->forceFill(['is_active' => $active, 'remember_token' => Str::random(60)])->save();
            $this->assertStillManageable();
        });

        $this->audit->record(new AuditEvent(
            action: $active ? 'platform.user.reactivated' : 'platform.user.blocked',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: $active ? AuditSeverity::Notice : AuditSeverity::Warning,
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            before: ['active' => ! $active],
            after: ['active' => $active],
            reason: $reason,
        ));
    }

    public function archive(PlatformUser $user, PlatformUser $actor, string $reason): void
    {
        $reason = $this->reason($reason);
        if ($user->is($actor)) {
            throw new DomainException(__('sadmin_users.errors.self'));
        }

        DB::connection('control')->transaction(function () use ($user): void {
            $user->forceFill(['is_active' => false, 'archived_at' => now(), 'remember_token' => Str::random(60)])->save();
            DB::connection('control')->table('support_tickets')->where('assigned_platform_user_id', $user->getKey())->update(['assigned_platform_user_id' => null]);
            $this->assertStillManageable();
        });

        $this->audit->record(new AuditEvent(
            action: 'platform.user.archived',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: AuditSeverity::Warning,
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            reason: $reason,
        ));
    }

    public function restore(PlatformUser $user, PlatformUser $actor, string $reason): void
    {
        $reason = $this->reason($reason);
        $user->forceFill(['archived_at' => null, 'is_active' => true])->save();

        $this->audit->record(new AuditEvent(
            action: 'platform.user.restored',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: AuditSeverity::Notice,
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            reason: $reason,
        ));
    }

    /**
     * Clears the enrolled authenticator: the user enrols again at next
     * sign-in. Recovery codes go with it.
     */
    public function resetMfa(PlatformUser $user, PlatformUser $actor, string $reason): void
    {
        $reason = $this->reason($reason);
        $user->forceFill([
            'mfa_secret' => $this->mfa->generateSecret(),
            'mfa_confirmed_at' => null,
            'mfa_recovery_codes' => null,
            'remember_token' => Str::random(60),
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'platform.user.mfa_reset',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            severity: AuditSeverity::Warning,
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            reason: $reason,
        ));
    }

    public function sendPasswordLink(PlatformUser $user, PlatformUser $actor): void
    {
        if (! $user->is_active || $user->archived_at !== null) {
            throw new DomainException(__('sadmin_users.errors.inactive'));
        }
        $this->sendAccessLink($user, 'reset');

        $this->audit->record(new AuditEvent(
            action: 'platform.user.password_link_sent',
            category: AuditCategory::Security,
            actor: Actor::platform($actor),
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
        ));
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<int>
     */
    private function assignableRoles(array $roleIds, PlatformUser $actor): array
    {
        $roles = PlatformRole::query()->whereIn('id', $roleIds)->whereNull('archived_at')->get();
        if ($roles->count() !== count(array_unique($roleIds))) {
            throw new DomainException(__('sadmin_users.errors.role_missing'));
        }
        $held = $actor->permissions();
        foreach ($roles as $role) {
            if (array_diff($role->permissions(), $held) !== []) {
                throw new DomainException(__('sadmin_users.errors.escalation', ['role' => $role->name['en'] ?? $role->key]));
            }
        }

        return array_values(array_map('intval', $roles->pluck('id')->all()));
    }

    /** Someone must always be able to manage platform users. */
    private function assertStillManageable(): void
    {
        $managers = DB::connection('control')->table('platform_users')
            ->join('platform_user_roles', 'platform_user_roles.user_id', '=', 'platform_users.id')
            ->join('platform_role_permissions', 'platform_role_permissions.role_id', '=', 'platform_user_roles.role_id')
            ->where('platform_users.is_active', true)->whereNull('platform_users.archived_at')
            ->where('platform_role_permissions.permission', 'platform.user.manage')
            ->distinct()->count('platform_users.id');

        if ($managers < 1) {
            throw new DomainException(__('sadmin_users.errors.last_manager'));
        }
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<string>
     */
    private function roleKeys(array $roleIds): array
    {
        return array_values(PlatformRole::query()->whereIn('id', $roleIds)->pluck('key')->map(fn (mixed $key): string => (string) $key)->all());
    }

    private function sendAccessLink(PlatformUser $user, string $kind): void
    {
        $token = Password::broker($user->password_changed_at === null ? 'platform_invitations' : 'platform')->createToken($user);
        Mail::to($user->email)->queue(new PlatformAccessEmail($user->name, $kind, route('superadmin.password.reset', ['token' => $token])));
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new DomainException(__('sadmin_users.errors.reason'));
        }

        return $reason;
    }
}
