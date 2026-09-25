<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Memberships\Domain\Enums\CustomerMembershipStatus;
use App\Modules\Memberships\Domain\Models\CustomerMembership;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads memberships for staff. `membership.view`, no entitlement: a customer's
 * memberships stay readable — and usable — after a downgrade
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 *
 * READ-ONLY. Activation is the after-commit sync's job, repaired by
 * `metastyle:reconcile`; using a benefit syncs the customer first (§1).
 */
final class MembershipsQuery
{
    public const MAX_LIST = 100;

    public function __construct(
        private readonly MembershipsAccess $access,
    ) {}

    /**
     * @return list<MembershipPlan>
     *
     * @throws AuthorizationException
     */
    public function plans(User $user, bool $includeArchived = false): array
    {
        $this->access->authorize($user, Permission::MembershipView, __('manager_benefits.errors.may_not_view_memberships'));

        $query = MembershipPlan::query()->with('benefits.service')->orderBy('sort_order')->orderBy('id');

        if (! $includeArchived) {
            $query->active();
        }

        /** @var list<MembershipPlan> $plans */
        $plans = $query->limit(self::MAX_LIST)->get()->all();

        return $plans;
    }

    /**
     * @throws AuthorizationException
     */
    public function plan(string $uuid, User $user): MembershipPlan
    {
        $this->access->authorize($user, Permission::MembershipManage, __('manager_benefits.errors.may_not_change_plans'));

        /** @var MembershipPlan|null $plan */
        $plan = MembershipPlan::query()->where('uuid', $uuid)->with('benefits.service')->first();

        if (! $plan instanceof MembershipPlan) {
            throw new NotFoundHttpException;
        }

        return $plan;
    }

    /**
     * A customer's memberships, newest term first, with their benefits — two
     * queries.
     *
     * @return array{customer: Customer, memberships: list<CustomerMembership>}
     *
     * @throws AuthorizationException
     */
    public function forCustomer(string $customerUuid, User $user): array
    {
        $this->access->authorize($user, Permission::MembershipView, __('manager_benefits.errors.may_not_view_memberships'));

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('uuid', $customerUuid)->first();

        if (! $customer instanceof Customer) {
            throw new NotFoundHttpException;
        }

        return ['customer' => $customer, 'memberships' => $this->ofCustomer((int) $customer->getKey())];
    }

    /**
     * A customer's memberships, as recorded. Also the customer's own view uses
     * this.
     *
     * @return list<CustomerMembership>
     */
    public function ofCustomer(int $customerId): array
    {
        /** @var list<CustomerMembership> $memberships */
        $memberships = CustomerMembership::query()
            ->where('customer_id', $customerId)
            ->with('benefits')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LIST)
            ->get()
            ->all();

        return $memberships;
    }

    /**
     * How many memberships of each plan are in force or about to start — one
     * grouped query, keyed by the PLAN's uuid (no numeric ids leave here).
     *
     * @return array<string, int>
     *
     * @throws AuthorizationException
     */
    public function liveCountsByPlan(User $user, ?CarbonImmutable $now = null): array
    {
        $this->access->authorize($user, Permission::MembershipView, __('manager_benefits.errors.may_not_view_memberships'));

        /** @var array<string, int|string> $counts */
        $counts = $this->live($now)
            ->join('membership_plans', 'membership_plans.id', '=', 'customer_memberships.membership_plan_id')
            ->groupBy('membership_plans.uuid')
            ->selectRaw('membership_plans.uuid as plan_uuid, COUNT(*) as total')
            ->toBase()
            ->pluck('total', 'plan_uuid')
            ->all();

        return array_map(static fn (int|string $n): int => (int) $n, $counts);
    }

    /**
     * The customers whose membership is in force or about to start, soonest
     * ending first — the members list on the plans page. Customer NAME only.
     *
     * @return LengthAwarePaginator<int, CustomerMembership>
     *
     * @throws AuthorizationException
     */
    public function members(User $user, ?string $planUuid = null, string $search = '', int $perPage = 20, ?CarbonImmutable $now = null): LengthAwarePaginator
    {
        $this->access->authorize($user, Permission::MembershipView, __('manager_benefits.errors.may_not_view_memberships'));

        $search = trim($search);

        return $this->live($now)
            ->with(['customer', 'benefits'])
            ->when($planUuid !== null && $planUuid !== '', fn (Builder $q) => $q->whereHas(
                'plan',
                fn (Builder $plan) => $plan->where('uuid', $planUuid),
            ))
            ->when($search !== '', fn (Builder $q) => $q->whereHas(
                'customer',
                fn (Builder $customer) => $customer->where('name', 'like', '%'.$search.'%'),
            ))
            ->orderBy('expires_at')
            ->orderBy('id')
            ->paginate(max(1, min($perPage, self::MAX_LIST)));
    }

    /**
     * Whether the center has any membership HISTORY — a plan or a membership
     * sold. Decides, after a downgrade, between the locked page and the
     * read-only one (docs/21 §22).
     */
    public function hasHistory(): bool
    {
        return MembershipPlan::query()->exists() || CustomerMembership::query()->exists();
    }

    /**
     * Not cancelled and not yet ended: in force now, or starting later.
     *
     * @return Builder<CustomerMembership>
     */
    private function live(?CarbonImmutable $now): Builder
    {
        return CustomerMembership::query()
            ->where('customer_memberships.status', CustomerMembershipStatus::Active->value)
            ->where('customer_memberships.expires_at', '>', ($now ?? CarbonImmutable::now())->utc());
    }
}
