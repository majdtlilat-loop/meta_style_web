<?php

declare(strict_types=1);

namespace App\Modules\Branches\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Retires a branch without deleting it.
 *
 * ARCHIVE, NEVER DELETE. Employees are assigned to branches today, and from
 * Phase 6 appointments and invoices will reference them. A hard delete would
 * orphan records that a center is legally required to be able to produce, and
 * the cascade would take the history with it (docs/13-ROADMAP.md Phase 4 §20).
 *
 * The main branch cannot be archived. It is what provisioning created, what
 * `Branch::main()` returns, and what an owner with no explicit branch scope
 * falls back to — archiving it would leave a center with no answer to "where
 * does this happen".
 */
final class ArchiveBranch
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(Branch $branch, User $actingUser): Branch
    {
        if (! $actingUser->hasPermission(Permission::BranchManage)) {
            throw new AuthorizationException(__('manager_staff.errors.branch_manage_denied'));
        }

        if (! $actingUser->branchScope()->allows($branch->id)) {
            throw new AuthorizationException(__('manager_staff.errors.branch_denied'));
        }

        if ($branch->is_main) {
            throw ValidationException::withMessages([
                'branch' => __('manager_staff.errors.branch_main_archive'),
            ]);
        }

        $branch->forceFill([
            'archived_at' => Carbon::now(),
            // Archived implies both: it stops appearing on the public menu and
            // stops being selectable operationally. Leaving `is_active` true on
            // an archived row is the kind of half-state that produces a branch
            // that is invisible in one list and present in another.
            'is_active' => false,
            'is_public' => false,
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'branches.branch.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Branch::class,
            targetId: $branch->uuid,
            targetLabel: (string) $branch->name,
            after: ['archived_at' => $branch->archived_at?->toIso8601String()],
        ));

        return $branch;
    }
}
