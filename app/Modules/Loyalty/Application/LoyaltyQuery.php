<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Models\LoyaltyRuleVersion;
use App\Modules\Loyalty\Domain\Models\LoyaltyTier;
use App\Modules\Loyalty\Domain\Models\LoyaltyTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads loyalty for staff. Needs `loyalty.view` and never the entitlement:
 * points history stays readable after a downgrade
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 *
 * READ-ONLY. Nothing here writes — not even a repair. The after-commit sync
 * keeps accounts current; `metastyle:reconcile` repairs a sync that failed; and
 * every Action that CHANGES a balance reconciles the customer first, where a
 * stale balance could produce a wrong decision (§1).
 */
final class LoyaltyQuery
{
    public function __construct(
        private readonly LoyaltyAccess $access,
        private readonly LoyaltyAccounts $accounts,
    ) {}

    /**
     * A customer's points, as recorded.
     *
     * @return array{customer: Customer, account: LoyaltyAccount|null}
     *
     * @throws AuthorizationException
     */
    public function forCustomer(string $customerUuid, User $user): array
    {
        $this->access->authorize($user, Permission::LoyaltyView, __('manager_benefits.errors.may_not_view_points'));

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('uuid', $customerUuid)->first();

        if (! $customer instanceof Customer) {
            throw new NotFoundHttpException;
        }

        return ['customer' => $customer, 'account' => $this->accountOf((int) $customer->getKey())];
    }

    /**
     * The account for one customer, as recorded. Also the customer's own view
     * uses this.
     */
    public function accountOf(int $customerId): ?LoyaltyAccount
    {
        return $this->accounts->find($customerId);
    }

    /**
     * The center's rules, for staff. Null until somebody sets them.
     *
     * @throws AuthorizationException
     */
    public function program(User $user): ?LoyaltyProgram
    {
        $this->access->authorize($user, Permission::LoyaltyView, __('manager_benefits.errors.may_not_view_program'));

        return LoyaltyProgram::current();
    }

    /**
     * The rules as they stand, for a surface that has already decided who may
     * see them — the customer's own view of their points.
     */
    public function currentProgram(): ?LoyaltyProgram
    {
        return LoyaltyProgram::current();
    }

    /**
     * @return list<LoyaltyTier>
     *
     * @throws AuthorizationException
     */
    public function tiers(User $user, bool $includeArchived = false): array
    {
        $this->access->authorize($user, Permission::LoyaltyView, __('manager_benefits.errors.may_not_view_tiers'));

        $query = LoyaltyTier::query()->orderBy('threshold_points')->orderBy('id');

        if (! $includeArchived) {
            $query->active();
        }

        /** @var list<LoyaltyTier> $tiers */
        $tiers = $query->limit(50)->get()->all();

        return $tiers;
    }

    /**
     * @throws AuthorizationException
     */
    public function tier(string $uuid, User $user): LoyaltyTier
    {
        $this->access->authorize($user, Permission::LoyaltyManage, __('manager_benefits.errors.may_not_change_tiers'));

        /** @var LoyaltyTier|null $tier */
        $tier = LoyaltyTier::query()->where('uuid', $uuid)->first();

        if (! $tier instanceof LoyaltyTier) {
            throw new NotFoundHttpException;
        }

        return $tier;
    }

    /**
     * The customers who hold points, largest balance first — for the loyalty
     * page's member list. Names only: a member list is not a contact list, and
     * any contact a screen wants comes through the Customers presenter.
     *
     * @return LengthAwarePaginator<int, LoyaltyAccount>
     *
     * @throws AuthorizationException
     */
    public function members(User $user, string $search = '', int $perPage = 20): LengthAwarePaginator
    {
        $this->access->authorize($user, Permission::LoyaltyView, __('manager_benefits.errors.may_not_view_points'));

        $search = trim($search);

        return LoyaltyAccount::query()
            ->with('customer')
            ->where(fn (Builder $q) => $q->where('balance', '>', 0)->orWhere('lifetime_points', '>', 0))
            ->when($search !== '', fn (Builder $q) => $q->whereHas(
                'customer',
                fn (Builder $customer) => $customer->where('name', 'like', '%'.$search.'%'),
            ))
            ->orderByDesc('balance')
            ->orderByDesc('lifetime_points')
            ->orderBy('id')
            ->paginate(max(1, min($perPage, 100)));
    }

    /**
     * The program at a glance: how many customers hold points, how many points
     * are outstanding, how many were ever earned and kept. One grouped query;
     * the account rows are the ledger's own cache (§4).
     *
     * @return array{members: int, outstanding_points: int, lifetime_points: int}
     *
     * @throws AuthorizationException
     */
    public function totals(User $user): array
    {
        $this->access->authorize($user, Permission::LoyaltyView, __('manager_benefits.errors.may_not_view_points'));

        /** @var object{members: int|string|null, outstanding: int|string|null, lifetime: int|string|null}|null $row */
        $row = LoyaltyAccount::query()
            ->toBase()
            ->selectRaw('SUM(CASE WHEN balance > 0 OR lifetime_points > 0 THEN 1 ELSE 0 END) as members')
            ->selectRaw('COALESCE(SUM(balance), 0) as outstanding')
            ->selectRaw('COALESCE(SUM(lifetime_points), 0) as lifetime')
            ->first();

        return [
            'members' => (int) ($row->members ?? 0),
            'outstanding_points' => (int) ($row->outstanding ?? 0),
            'lifetime_points' => (int) ($row->lifetime ?? 0),
        ];
    }

    /**
     * The earning rules as they changed, newest first. Append-only: each save
     * of the program is a version, and a repair always earns under the version
     * in force when the money moved (ADR-064).
     *
     * @return list<LoyaltyRuleVersion>
     *
     * @throws AuthorizationException
     */
    public function ruleVersions(User $user, int $limit = 6): array
    {
        $this->access->authorize($user, Permission::LoyaltyView, __('manager_benefits.errors.may_not_view_program'));

        /** @var list<LoyaltyRuleVersion> $versions */
        $versions = LoyaltyRuleVersion::query()
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 50)))
            ->get()
            ->all();

        return $versions;
    }

    /**
     * Whether the center has any loyalty HISTORY — a program, a tier or a
     * points movement. Decides, after a downgrade, between the locked page
     * (nothing to read) and the read-only one (docs/21 §22).
     */
    public function hasHistory(): bool
    {
        return LoyaltyProgram::query()->exists()
            || LoyaltyTier::query()->exists()
            || LoyaltyTransaction::query()->exists();
    }
}
