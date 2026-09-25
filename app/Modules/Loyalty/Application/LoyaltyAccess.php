<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Two gates, as everywhere: the center owns `loyalty`, and this person holds
 * the permission. Points belong to the center's customers, not to a branch,
 * so no branch narrows them; a redemption is also bound to its sale's branch
 * by `SaleBenefits`.
 *
 * `ensure()` is for NEW activity — earning, redeeming, configuring, adjusting.
 * `authorize()` is for reading history and giving back what was taken, which a
 * downgrade must never block (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 */
final class LoyaltyAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, string $refusal): void
    {
        $this->entitlements->ensure('loyalty');

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
        return $this->entitlements->enabled('loyalty');
    }
}
