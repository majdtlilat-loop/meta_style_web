<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Packages\Domain\Enums\CustomerPackageStatus;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reads packages for staff. `package.view`, no entitlement: a customer's
 * packages stay readable — and usable — after a downgrade
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §22).
 *
 * READ-ONLY. Activation is the after-commit sync's job, repaired by
 * `metastyle:reconcile`; using a package reconciles the customer first (§1).
 */
final class PackagesQuery
{
    public const MAX_LIST = 100;

    public function __construct(
        private readonly PackagesAccess $access,
    ) {}

    /**
     * @return list<PackageDefinition>
     *
     * @throws AuthorizationException
     */
    public function definitions(User $user, bool $includeArchived = false): array
    {
        $this->access->authorize($user, Permission::PackageView, __('manager_benefits.errors.may_not_view_packages'));

        $query = PackageDefinition::query()->with(['items.service', 'items.variation'])->orderBy('sort_order')->orderBy('id');

        if (! $includeArchived) {
            $query->active();
        }

        /** @var list<PackageDefinition> $definitions */
        $definitions = $query->limit(self::MAX_LIST)->get()->all();

        return $definitions;
    }

    /**
     * @throws AuthorizationException
     */
    public function definition(string $uuid, User $user): PackageDefinition
    {
        $this->access->authorize($user, Permission::PackageManage, __('manager_benefits.errors.may_not_change_packages'));

        /** @var PackageDefinition|null $definition */
        $definition = PackageDefinition::query()->where('uuid', $uuid)->with(['items.service', 'items.variation'])->first();

        if (! $definition instanceof PackageDefinition) {
            throw new NotFoundHttpException;
        }

        return $definition;
    }

    /**
     * A customer's packages, newest first, with their items — two queries.
     *
     * @return array{customer: Customer, packages: list<CustomerPackage>}
     *
     * @throws AuthorizationException
     */
    public function forCustomer(string $customerUuid, User $user): array
    {
        $this->access->authorize($user, Permission::PackageView, __('manager_benefits.errors.may_not_view_packages'));

        /** @var Customer|null $customer */
        $customer = Customer::query()->where('uuid', $customerUuid)->first();

        if (! $customer instanceof Customer) {
            throw new NotFoundHttpException;
        }

        return ['customer' => $customer, 'packages' => $this->ofCustomer((int) $customer->getKey())];
    }

    /**
     * A customer's packages, as recorded. Also the customer's own view uses
     * this.
     *
     * @return list<CustomerPackage>
     */
    public function ofCustomer(int $customerId): array
    {
        /** @var list<CustomerPackage> $packages */
        $packages = CustomerPackage::query()
            ->where('customer_id', $customerId)
            ->with('items')
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LIST)
            ->get()
            ->all();

        return $packages;
    }

    /**
     * How many packages of each definition are active and unexpired — one
     * grouped query, keyed by the DEFINITION's uuid (no numeric ids leave).
     *
     * @return array<string, int>
     *
     * @throws AuthorizationException
     */
    public function liveCountsByDefinition(User $user, ?CarbonImmutable $now = null): array
    {
        $this->access->authorize($user, Permission::PackageView, __('manager_benefits.errors.may_not_view_packages'));

        /** @var array<string, int|string> $counts */
        $counts = $this->live($now)
            ->join('package_definitions', 'package_definitions.id', '=', 'customer_packages.package_definition_id')
            ->groupBy('package_definitions.uuid')
            ->selectRaw('package_definitions.uuid as definition_uuid, COUNT(*) as total')
            ->toBase()
            ->pluck('total', 'definition_uuid')
            ->all();

        return array_map(static fn (int|string $n): int => (int) $n, $counts);
    }

    /**
     * The customers holding an active, unexpired package, soonest ending first
     * — with their items, for the sessions left. Customer NAME only.
     *
     * @return LengthAwarePaginator<int, CustomerPackage>
     *
     * @throws AuthorizationException
     */
    public function holders(User $user, ?string $definitionUuid = null, string $search = '', int $perPage = 20, ?CarbonImmutable $now = null): LengthAwarePaginator
    {
        $this->access->authorize($user, Permission::PackageView, __('manager_benefits.errors.may_not_view_packages'));

        $search = trim($search);

        return $this->live($now)
            ->with(['customer', 'items'])
            ->when($definitionUuid !== null && $definitionUuid !== '', fn (Builder $q) => $q->whereHas(
                'definition',
                fn (Builder $definition) => $definition->where('uuid', $definitionUuid),
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
     * Whether the center has any package HISTORY — a definition or a package
     * sold. Decides, after a downgrade, between the locked page and the
     * read-only one (docs/21 §22).
     */
    public function hasHistory(): bool
    {
        return PackageDefinition::query()->exists() || CustomerPackage::query()->exists();
    }

    /**
     * Not cancelled and not yet expired.
     *
     * @return Builder<CustomerPackage>
     */
    private function live(?CarbonImmutable $now): Builder
    {
        return CustomerPackage::query()
            ->where('customer_packages.status', CustomerPackageStatus::Active->value)
            ->where('customer_packages.expires_at', '>', ($now ?? CarbonImmutable::now())->utc());
    }
}
