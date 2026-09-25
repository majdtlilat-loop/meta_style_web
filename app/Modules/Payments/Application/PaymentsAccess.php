<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The gates in front of money, in one place.
 *
 * ## Which entitlement a payment needs
 *
 *   cash, manual electronic, their refunds    `pos`       — the desk; no online
 *                                                          payments needed to take
 *                                                          cash (docs/19-PAYMENTS.md §2)
 *   gateway initiation, account configuration `payments`
 *   provider refunds                          `payments`
 *   reading payments and refunds              none        — history (§3)
 *   provider callbacks                        none        — money already moving
 *                                                          cannot depend on today's
 *                                                          package (§23)
 *
 * Permission and branch always; never a role name, no Owner bypass.
 */
final class PaymentsAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Money at the desk: `pos`, the permission, the branch.
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensureDesk(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        $this->entitlements->ensure('pos');

        $this->authorize($user, $permission, $branchId, $refusal);
    }

    /**
     * Anything that talks to a payment provider on the center's initiative:
     * `payments`, the permission, the branch.
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensureGateway(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        $this->entitlements->ensure('payments');

        $this->authorize($user, $permission, $branchId, $refusal);
    }

    /**
     * Reading what already happened: the permission and the branch only.
     *
     * @throws AuthorizationException
     */
    public function authorize(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($refusal);
        }

        if ($branchId > 0 && ! $user->canAccessBranch($branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }

    public function onlinePaymentsEnabled(): bool
    {
        return $this->entitlements->enabled('payments');
    }
}
