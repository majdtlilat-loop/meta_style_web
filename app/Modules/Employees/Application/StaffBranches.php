<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Where a member of staff may be assigned.
 *
 * A branch id arrives from a form or an API body, so it is checked rather than
 * trusted: it must exist (a foreign-key error is a 500, not an answer), a NEW
 * assignment must be to a live branch, and every branch touched must be one
 * the actor runs. A scoped actor must leave the person in at least one of
 * their branches — otherwise they would create staff that no branch of theirs
 * can ever reach again.
 */
final class StaffBranches
{
    /**
     * @param  list<int>  $branchIds  the requested assignment
     * @param  list<int>  $current  the assignment being replaced (empty on create)
     * @return list<int>
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public static function resolve(array $branchIds, User $actor, array $current = []): array
    {
        $branchIds = array_values(array_unique(array_map('intval', $branchIds)));
        $scope = $actor->branchScope();

        if ($branchIds !== []) {
            $rows = Branch::query()->whereIn('id', $branchIds)->get(['id', 'archived_at', 'is_active']);

            if ($rows->count() !== count($branchIds)) {
                throw ValidationException::withMessages(['branches' => __('manager_staff.errors.branch_unknown')]);
            }

            foreach ($rows as $branch) {
                $isNew = ! in_array((int) $branch->id, $current, true);

                if ($isNew && $branch->isArchived()) {
                    throw ValidationException::withMessages(['branches' => __('manager_staff.errors.branch_unknown')]);
                }
            }
        }

        // Every branch added or removed must be one the actor runs.
        $touched = array_merge(array_diff($branchIds, $current), array_diff($current, $branchIds));

        foreach ($touched as $branchId) {
            if (! $scope->allows((int) $branchId)) {
                throw new AuthorizationException(__('manager_staff.errors.branch_denied'));
            }
        }

        if (! $scope->isUnrestricted() && $branchIds === []) {
            throw ValidationException::withMessages(['branches' => __('manager_staff.errors.branch_required')]);
        }

        return $branchIds;
    }
}
