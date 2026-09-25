<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Actions;

use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Whether one staff account may change another's access.
 *
 * REACH — the target's whole branch scope must sit inside the actor's. A
 * manager of one branch cannot re-role or re-scope someone who also works
 * elsewhere, nor anyone with access to every branch.
 *
 * RANK — the target may hold nothing the actor does not. Changing the access
 * of a more powerful account is how a manager would disable or redirect the
 * people who supervise them.
 *
 * Account-level twins of the Employees module's `StaffGuard`, which cannot be
 * used from the Kernel (the Kernel never imports a business module).
 */
final class StaffAccessRules
{
    /**
     * @throws AuthorizationException
     */
    public static function assertReaches(User $target, User $actor): void
    {
        $scope = $actor->branchScope();

        if ($scope->isUnrestricted()) {
            return;
        }

        $targetScope = $target->branchScope();

        if ($target->all_branches || $targetScope->isUnrestricted()) {
            throw new AuthorizationException(__('permissions.errors.outside_scope'));
        }

        $ids = $target->is_active
            ? ($targetScope->branchIds ?? [])
            : self::storedBranchIds($target);

        if ($ids === []) {
            throw new AuthorizationException(__('permissions.errors.outside_scope'));
        }

        foreach ($ids as $branchId) {
            if (! $scope->allows($branchId)) {
                throw new AuthorizationException(__('permissions.errors.outside_scope'));
            }
        }
    }

    /**
     * @throws AuthorizationException
     */
    public static function assertNotOutranked(User $target, User $actor): void
    {
        if (array_diff($target->permissions(), $actor->permissions()) !== []) {
            throw new AuthorizationException(__('permissions.errors.above_you'));
        }
    }

    /**
     * The scope as stored, even while the account is disabled (a disabled
     * account's live scope is empty by design).
     *
     * @return list<int>
     */
    public static function storedBranchIds(User $user): array
    {
        /** @var list<int> $ids */
        $ids = $user->getConnection()
            ->table('user_branches')
            ->where('user_id', $user->getKey())
            ->pluck('branch_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
