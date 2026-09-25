<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The gates every review operation passes.
 *
 *   1. the CENTER owns `reviews`        — NEW activity only
 *   2. the USER holds the permission    — never a role name, no Owner bypass
 *   3. the user may work at the BRANCH  — a review belongs to a visit, which
 *                                         belongs to one branch
 *
 * ## What a downgrade stops, and what it must not
 *
 * The Booking rule again (docs/05-ENTITLEMENTS.md §6.2), and here it has a
 * second, sharper edge. Losing `reviews` stops the center ISSUING new
 * invitations — {@see ensure()}. It does NOT stop:
 *
 *   - a customer submitting through a link the center already gave them. That
 *     capability was granted while the center owned the feature, and revoking
 *     it afterwards would take something back from a customer for a decision
 *     they had no part in (docs/22-REVIEWS.md §18);
 *   - staff reading the reviews they already collected;
 *   - staff HIDING one. A center that cannot moderate what is already published
 *     is worse off than a center with no reviews at all.
 *
 * Those three go through {@see authorize()}, which never asks about the
 * entitlement.
 */
final class ReviewsAccess
{
    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * New activity — issuing a customer a review link.
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function ensure(User $user, Permission $permission, int $branchId, string $refusal): void
    {
        $this->entitlements->ensure('reviews');

        $this->authorize($user, $permission, $branchId, $refusal);
    }

    /**
     * Reading what was collected, and moderating it. Never the entitlement.
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

    /**
     * Whether the center may issue invitations at all right now. Used by the
     * after-commit listener, which has no user and must not throw.
     */
    public function enabled(): bool
    {
        return $this->entitlements->enabled('reviews');
    }
}
