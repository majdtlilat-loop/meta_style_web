<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;

/**
 * Which staff should hear about something.
 *
 * ## By permission and branch, never by role name
 *
 * "Tell the managers" is not a rule this system can express, and deliberately
 * so: a center is free to rename its roles, split them, or hand
 * `review.manage` to one senior stylist and nobody else (ADR-029). The question
 * is therefore always "who may act on this", which means who holds the
 * PERMISSION and may work in the BRANCH it happened in
 * (docs/23-NOTIFICATIONS.md §5).
 *
 * A branch-scoped alert therefore reaches the people who could do something
 * about it, and nobody else — a Branch A manager never sees Branch B's
 * one-star review appear in their inbox.
 *
 * ## Within one center, and only one
 *
 * Every user read here lives in the tenant's own database. There is no
 * cross-center audience and no place to ask for one; a platform-wide
 * announcement belongs to Super Admin, in its own phase (§6).
 */
final class StaffTargets
{
    /**
     * Staff who hold `$permission` and may work in `$branchId` (all branches
     * when it is null).
     *
     * Deactivated accounts are excluded by `hasPermission()` itself, so a
     * session that outlives a deactivation gets nothing new either.
     *
     * @return list<Recipient>
     */
    public function withPermission(Permission $permission, ?int $branchId = null): array
    {
        /** @var list<User> $users */
        $users = User::query()->where('is_active', true)->orderBy('id')->get()->all();

        $recipients = [];

        foreach ($users as $user) {
            if (! $user->hasPermission($permission)) {
                continue;
            }

            if ($branchId !== null && $branchId > 0 && ! $user->canAccessBranch($branchId)) {
                continue;
            }

            $recipients[] = new Recipient(RecipientKind::Staff, (int) $user->getKey());
        }

        return $recipients;
    }
}
