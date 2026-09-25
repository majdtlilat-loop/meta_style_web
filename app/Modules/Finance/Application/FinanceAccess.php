<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Finance's gates.
 *
 *   dashboard, expense categories, posting and voiding expenses,
 *   the counted-cash close                                   `finance`
 *   reading the ledger, expenses and past reconciliations    none — history
 *
 * Losing `finance` stops new finance operations. It deletes nothing and hides
 * nothing that already happened (docs/20-FINANCE.md §3).
 */
final class FinanceAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        $this->entitlements->ensure('finance');

        $this->authorize($user, $permission, $branchId, $refusal);
    }

    /**
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

    public function enabled(): bool
    {
        return $this->entitlements->enabled('finance');
    }
}
