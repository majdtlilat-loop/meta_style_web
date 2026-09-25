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
use Illuminate\Validation\ValidationException;

/**
 * Brings an archived branch back.
 *
 * It returns ACTIVE — staff can work there and bookings can be taken again —
 * but stays HIDDEN from the public menu and booking page until somebody
 * deliberately shows it. Archiving hid it from customers; undoing an archive
 * should not re-publish a location nobody has checked yet.
 *
 * The same two gates as every branch change: `branch.manage` and branch scope.
 */
final class RestoreBranch
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Branch $branch, User $actingUser): Branch
    {
        if (! $actingUser->hasPermission(Permission::BranchManage)) {
            throw new AuthorizationException(__('manager_staff.errors.branch_manage_denied'));
        }

        if (! $actingUser->branchScope()->allows($branch->id)) {
            throw new AuthorizationException(__('manager_staff.errors.branch_denied'));
        }

        if (! $branch->isArchived()) {
            throw ValidationException::withMessages(['branch' => __('manager_staff.errors.branch_not_archived')]);
        }

        $branch->forceFill([
            'archived_at' => null,
            'is_active' => true,
            'is_public' => false,
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'branches.branch.restored',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Branch::class,
            targetId: $branch->uuid,
            targetLabel: (string) $branch->name,
            after: ['is_active' => true, 'is_public' => false],
        ));

        return $branch;
    }
}
