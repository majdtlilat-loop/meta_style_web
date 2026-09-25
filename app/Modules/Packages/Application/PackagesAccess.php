<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * `ensure()`: the center owns `packages` AND the person holds the permission —
 * for SELLING and DEFINING packages.
 *
 * `authorize()`: the permission alone — for reading, for cancelling, and for
 * USING a package somebody already paid for. A center that loses `packages`
 * stops selling them; the customers who bought one keep it until it expires
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 */
final class PackagesAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, string $refusal): void
    {
        $this->entitlements->ensure('packages');

        $this->authorize($user, $permission, $refusal);
    }

    /**
     * @throws AuthorizationException
     */
    public function authorize(User $user, Permission $permission, string $refusal): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($refusal);
        }
    }

    public function enabled(): bool
    {
        return $this->entitlements->enabled('packages');
    }
}
