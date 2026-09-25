<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The two questions every staff Action asks before it touches a person.
 *
 *  - REACH: does the actor's branch scope cover every branch this person
 *    works at? Permission alone is not authorization (docs/06 §5). A person
 *    with no branch at all is reachable only by someone unrestricted — a
 *    scoped manager must not be able to adopt, edit or disable staff that no
 *    branch of theirs owns.
 *
 *  - RANK: does this person hold any permission the actor does not? Nobody
 *    may grant what they do not hold, and the same principle stops a manager
 *    from disabling, re-scoping or re-linking an account more powerful than
 *    their own (an activation link for such an account would let the manager
 *    take it over).
 *
 * Both are answered here once, so the Actions and the presenter that decides
 * which buttons to draw can never disagree.
 */
final class StaffGuard
{
    public static function reaches(User $actor, Employee $employee): bool
    {
        $scope = $actor->branchScope();

        if ($scope->isUnrestricted()) {
            return true;
        }

        $branchIds = $employee->relationLoaded('branches')
            ? $employee->branches->map(static fn ($branch): int => (int) $branch->getKey())->all()
            : $employee->branchIds();

        if ($branchIds === []) {
            return false;
        }

        foreach ($branchIds as $branchId) {
            if (! $scope->allows((int) $branchId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws AuthorizationException
     */
    public static function assertReaches(User $actor, Employee $employee): void
    {
        if (! self::reaches($actor, $employee)) {
            throw new AuthorizationException(__('manager_staff.errors.outside_scope'));
        }
    }

    /**
     * True when the target holds at least one permission the actor lacks.
     */
    public static function outranks(User $target, User $actor): bool
    {
        return array_diff($target->permissions(), $actor->permissions()) !== [];
    }

    /**
     * @throws AuthorizationException
     */
    public static function assertNotOutranked(User $target, User $actor): void
    {
        if (self::outranks($target, $actor)) {
            throw new AuthorizationException(__('manager_staff.errors.above_you'));
        }
    }
}
