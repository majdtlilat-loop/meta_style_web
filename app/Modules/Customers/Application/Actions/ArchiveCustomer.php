<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retires a customer without deleting them.
 *
 * ARCHIVE, NEVER DELETE. From Phase 6 a customer is referenced by appointments,
 * from Phase 9 by invoices, and later by payments and reviews. A hard delete
 * would either orphan records a center is legally required to produce, or
 * cascade the history away with them (docs/13-ROADMAP.md Phase 5 §10).
 *
 * Archiving also DEACTIVATES the customer's login if they have one. Leaving an
 * archived customer able to sign in is the kind of half-state that produces a
 * person who is invisible to staff and still logging in.
 *
 * This is not erasure. A right-to-be-forgotten workflow needs anonymisation
 * rules per referencing module — which invoices must keep, which reviews become
 * anonymous — and those modules do not exist. The path is left open by keeping
 * PII in one table with one uuid, so a later anonymiser has one place to work.
 */
final class ArchiveCustomer
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(Customer $customer, User $actingUser): Customer
    {
        $this->authorize($actingUser);

        DB::connection('tenant')->transaction(function () use ($customer): void {
            $customer->forceFill(['archived_at' => Carbon::now()])->save();

            $customer->account()->update(['is_active' => false]);
        });

        $this->audit->record(new AuditEvent(
            action: 'crm.customer.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Customer::class,
            targetId: $customer->uuid,
            targetLabel: $customer->name,
            after: ['archived_at' => $customer->archived_at?->toIso8601String()],
        ));

        return $customer;
    }

    /**
     * Brings a customer back.
     *
     * Their login is NOT reactivated with them. Restoring a CRM record is a
     * clerical correction; restoring someone's ability to sign in is a
     * different decision, and it needs `customer.account.manage`.
     */
    public function restore(Customer $customer, User $actingUser): Customer
    {
        $this->authorize($actingUser);

        $customer->forceFill(['archived_at' => null])->save();

        $this->audit->record(new AuditEvent(
            action: 'crm.customer.restored',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Customer::class,
            targetId: $customer->uuid,
            targetLabel: $customer->name,
        ));

        return $customer;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::CustomerArchive)) {
            throw new AuthorizationException('You may not archive customers.');
        }
    }
}
