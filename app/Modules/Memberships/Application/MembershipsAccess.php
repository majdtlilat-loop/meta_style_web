<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * `ensure()`: the center owns `memberships` AND the person holds the
 * permission — for SELLING and DEFINING plans.
 *
 * `authorize()`: the permission alone — for reading, for cancelling, and for
 * USING a membership somebody already paid for. A center that loses
 * `memberships` stops selling them; members keep theirs until their term ends
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 */
final class MembershipsAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, string $refusal): void
    {
        $this->entitlements->ensure('memberships');

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
        return $this->entitlements->enabled('memberships');
    }
}
