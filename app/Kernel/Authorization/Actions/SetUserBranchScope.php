<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets which branches a staff account may act in — the second half of
 * authorization (permission ∩ branch scope, docs/06 §5).
 *
 * `User::syncBranchScope()` writes the pivot; this is the authorised way to
 * call it:
 *
 *  - `staff.access.manage`;
 *  - never the owner's scope, never the actor's own (widening your own reach
 *    is the escalation this exists to stop);
 *  - the target must be inside the actor's scope and hold nothing the actor
 *    does not ({@see StaffAccessRules});
 *  - every requested branch must be one the actor runs, and "every branch" —
 *    which includes branches not created yet — only from someone who has it;
 *  - at least one branch: an account scoped to nothing is a login that
 *    authorises nothing, which is what deactivation is for.
 *
 * Branch ids are checked against the `branches` table directly: the Kernel
 * never imports the Branches module (docs/04-MODULE-BOUNDARIES.md §2).
 */
final class SetUserBranchScope
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  list<int>  $branchIds  ignored when $allBranches is true
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(User $user, bool $allBranches, array $branchIds, User $actingUser): User
    {
        if (! $actingUser->hasPermission(Permission::StaffAccessManage)) {
            throw new AuthorizationException(__('permissions.errors.access_denied'));
        }

        if ($user->is_owner) {
            throw new AuthorizationException(__('permissions.errors.owner_scope'));
        }

        if ((int) $user->getKey() === (int) $actingUser->getKey()) {
            throw new AuthorizationException(__('permissions.errors.self_scope'));
        }

        StaffAccessRules::assertReaches($user, $actingUser);
        StaffAccessRules::assertNotOutranked($user, $actingUser);

        $scope = $actingUser->branchScope();
        $branchIds = $allBranches ? [] : array_values(array_unique(array_map('intval', $branchIds)));
        $stored = StaffAccessRules::storedBranchIds($user);

        if ($allBranches && ! $scope->isUnrestricted()) {
            throw new AuthorizationException(__('permissions.errors.scope_all_denied'));
        }

        if (! $allBranches) {
            if ($branchIds === []) {
                throw ValidationException::withMessages(['scope' => __('permissions.errors.scope_required')]);
            }

            $rows = DB::connection('tenant')->table('branches')
                ->whereIn('id', $branchIds)
                ->get(['id', 'archived_at']);

            if ($rows->count() !== count($branchIds)) {
                throw ValidationException::withMessages(['scope' => __('permissions.errors.scope_unknown')]);
            }

            // A branch archived since it was granted may stay; a NEW grant
            // must be to a live one.
            foreach ($rows as $row) {
                if ($row->archived_at !== null && ! in_array((int) $row->id, $stored, true)) {
                    throw ValidationException::withMessages(['scope' => __('permissions.errors.scope_unknown')]);
                }
            }

            foreach ($branchIds as $branchId) {
                if (! $scope->allows($branchId)) {
                    throw new AuthorizationException(__('permissions.errors.scope_branch_denied'));
                }
            }
        }

        $before = [
            'all_branches' => $user->all_branches,
            'branches' => $stored,
        ];

        DB::connection('tenant')->transaction(function () use ($user, $allBranches, $branchIds): void {
            $user->forceFill(['all_branches' => $allBranches])->save();
            $user->syncBranchScope($branchIds);
        });

        $this->audit->record(new AuditEvent(
            action: 'authorization.user.scope_changed',
            category: AuditCategory::Security,
            actor: Actor::staff($actingUser),
            severity: AuditSeverity::Critical,
            targetType: User::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
            before: $before,
            after: ['all_branches' => $allBranches, 'branches' => $branchIds],
        ));

        return $user;
    }
}
