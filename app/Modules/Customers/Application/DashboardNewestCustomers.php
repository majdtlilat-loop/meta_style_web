<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;

/**
 * The most recently added customers, for the Manager dashboard.
 *
 * A customer belongs to the CENTER, not to a branch (no `branch_id`, locked),
 * so this is the same center-wide list the customer directory shows anyone
 * holding `customer.view`. Name, source and when they were added — never a
 * phone or an email: contact details stay behind CustomerPresenter's masking.
 */
final class DashboardNewestCustomers
{
    /**
     * @return list<array{uuid: string, name: string, source: string, registered: bool, created_at: string|null}>
     */
    public function latest(User $viewer, int $limit = 5): array
    {
        if (! $viewer->hasPermission(Permission::CustomerView)) {
            return [];
        }

        return Customer::query()
            ->active()
            ->with('account')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, min(20, $limit)))
            ->get(['id', 'uuid', 'name', 'source', 'created_at'])
            ->map(static fn (Customer $customer): array => [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                'source' => $customer->source->value,
                'registered' => $customer->isRegistered(),
                'created_at' => $customer->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
