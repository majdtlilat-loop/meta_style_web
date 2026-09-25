<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Application;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Authorization\SystemRoleSynchroniser;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\SaaS\Exceptions\RegistrationFailed;
use App\Kernel\SaaS\Models\Registration;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Menu\Application\MenuPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Turns a freshly provisioned, empty tenant database into a usable center.
 *
 * Runs INSIDE tenant context, after the database exists and the tenant
 * migrations have been applied. Everything here is idempotent, because
 * provisioning is resumable: a retry after a mid-pipeline failure re-runs this
 * and must not produce a second main branch or a second owner
 * (docs/02-TENANCY.md §8.2).
 */
final class CenterBootstrapper
{
    public function __construct(
        private readonly SystemRoleSynchroniser $roles,
        private readonly TenantLocales $locales,
        private readonly MenuPublisher $menu,
    ) {}

    /**
     * @return User the owner account
     */
    public function bootstrap(Registration $registration): User
    {
        // System roles first: the owner account is worthless without a role to
        // attach, and the Owner role is what actually carries the permissions.
        $this->roles->seedAndSync();

        $branch = $this->mainBranch($registration);
        $owner = $this->owner($registration);

        // The owner sees every branch, including ones created later — which is
        // why this is a flag rather than a row per branch.
        if (! $owner->all_branches) {
            $owner->forceFill(['all_branches' => true])->save();
        }

        $ownerRole = Role::query()->where('key', SystemRole::Owner->value)->firstOrFail();

        $owner->roles()->syncWithoutDetaching([$ownerRole->id]);
        $owner->forgetPermissionCache();

        // A published menu from the first moment, so the center's public page
        // works before anyone opens the editor. Seeded from the default
        // template rather than left empty — a 404 on a QR code that has already
        // been printed is not recoverable.
        $this->menu->seed();

        unset($branch);

        return $owner;
    }

    private function mainBranch(Registration $registration): Branch
    {
        $existing = Branch::query()->where('is_main', true)->first();

        if ($existing instanceof Branch) {
            return $existing;
        }

        /** @var Branch $branch */
        $branch = Branch::query()->create([
            // The center's own name is the sensible first branch name; the
            // owner renames it during the Phase 4 setup wizard.
            'name' => TranslatedText::make($registration->locale, $registration->center_name),
            'is_main' => true,
            'is_active' => true,
        ]);

        return $branch;
    }

    /**
     * Creates the owner login from the registration's stored credential.
     *
     * The password arrives already hashed — it was hashed in the web request
     * and never persisted in plaintext anywhere (ADR-028).
     */
    private function owner(Registration $registration): User
    {
        $existing = $this->findExistingOwner($registration);

        if ($existing instanceof User) {
            return $existing;
        }

        if ($registration->owner_password_hash === null) {
            // Reached when a retry outlives its credential window and no owner
            // was created before the failure. Failing with a named reason beats
            // creating a passwordless owner nobody can sign in as.
            throw RegistrationFailed::credentialExpired($registration->uuid);
        }

        $user = new User;

        $user->forceFill([
            'name' => $registration->owner_name,
            'email' => $registration->owner_email,
            'phone' => $registration->owner_phone,
            'is_active' => true,
            'is_owner' => true,
            'all_branches' => true,
            'password_changed_at' => now(),
        ]);

        // Assigned through the underlying attribute rather than the `hashed`
        // cast: the value is ALREADY a bcrypt hash, and letting the cast run
        // would hash the hash and lock the owner out of their own center.
        $user->setRawAttributes(array_merge($user->getAttributes(), [
            'password' => $registration->owner_password_hash,
        ]));

        $user->save();

        return $user;
    }

    private function findExistingOwner(Registration $registration): ?User
    {
        $query = User::query()->where('is_owner', true);

        if ($registration->owner_email !== null) {
            $query->where('email', $registration->owner_email);
        } elseif ($registration->owner_phone !== null) {
            $query->where('phone', $registration->owner_phone);
        }

        /** @var User|null $user */
        $user = $query->first();

        return $user;
    }

    /**
     * Records the tenant's own locale so later requests can fall back to it.
     *
     * Only the locale the center actually registered in is enabled. Turning all
     * three on for everyone would put empty Kurdish and Arabic fields on every
     * admin form of a center that will only ever use English, and empty fields
     * are how translatable content ends up half-filled
     * (docs/07-LOCALIZATION.md §4).
     */
    public function rememberLocale(Registration $registration): void
    {
        // A Super Admin may enable several languages up front; a
        // self-registration gets the one it registered in.
        $chosen = $registration->options['locales'] ?? null;
        $locales = is_array($chosen) && $chosen !== [] ? array_values(array_filter($chosen, 'is_string')) : [$registration->locale];
        if (! in_array($registration->locale, $locales, true)) {
            $locales[] = $registration->locale;
        }
        $this->locales->setEnabled($locales, $registration->locale);

        // Kept for the settings row's original readers. `TenantLocales` writes
        // the same key, so this is belt-and-braces rather than a second source
        // of truth.
        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => 'default_locale'],
            [
                'value' => json_encode($registration->locale, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
