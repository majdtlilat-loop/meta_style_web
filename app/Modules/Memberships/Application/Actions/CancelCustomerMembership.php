<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Memberships\Application\MembershipsAccess;
use App\Modules\Memberships\Application\UsedBenefits;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A manager cancels a customer's membership — with a reason.
 *
 * Status becomes cancelled and it stops being usable at once; its uses stay in
 * the history; nothing is deleted. Refunding the money is a separate, explicit
 * Payments refund — this moves no money. Allowed after a downgrade: it is
 * administration, not a new sale (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §21).
 */
final class CancelCustomerMembership
{
    public function __construct(
        private readonly MembershipsAccess $access,
        private readonly UsedBenefits $used,
    ) {}

    /**
     * @throws MembershipsFailed
     * @throws AuthorizationException
     */
    public function __invoke(string $membershipUuid, User $actingUser, string $reason, ?CarbonImmutable $now = null): CustomerMembership
    {
        $this->access->authorize($actingUser, Permission::MembershipManage, __('manager_benefits.errors.may_not_cancel_memberships'));

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 190) {
            throw MembershipsFailed::policy(__('manager_benefits.errors.cancel_membership_reason'));
        }

        $at = ($now ?? CarbonImmutable::now())->utc();

        /** @var CustomerMembership $membership */
        $membership = DB::connection('tenant')->transaction(function () use ($membershipUuid, $actingUser, $reason, $at): CustomerMembership {
            /** @var CustomerMembership|null $membership */
            $membership = CustomerMembership::query()->where('uuid', $membershipUuid)->lockForUpdate()->first();

            if (! $membership instanceof CustomerMembership) {
                throw new NotFoundHttpException;
            }

            $this->used->cancel($membership, $reason, Actor::staff($actingUser), $at);

            return $membership;
        });

        return $membership;
    }
}
