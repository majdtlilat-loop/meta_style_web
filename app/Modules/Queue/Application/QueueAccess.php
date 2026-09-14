<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Entitlement, permission and branch scope — the three checks every queue
 * Action makes, in one place.
 *
 * Written once rather than six times, because the failure mode of repeating it
 * is an Action that checks two of the three and nobody notices which. The
 * BRANCH check in particular is the one that gets forgotten: a permission alone
 * is not authorization in this product, and a host at Karrada holding
 * `queue.call` must not be able to call a number at Mansour
 * (docs/06-AUTH-ROLES-PERMISSIONS.md §5, docs/17-QUEUE.md §20).
 *
 * It is a plain service, not middleware: WhatsApp, jobs and console commands do
 * not pass through HTTP, and the entitlement rule exists precisely because of
 * them (docs/05-ENTITLEMENTS.md §6.2).
 */
final class QueueAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        $this->entitlements->ensure('queue_management');

        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException($refusal);
        }

        if ($branchId > 0 && ! $user->canAccessBranch($branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
