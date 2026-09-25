<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a customer's LOGIN on or off. The CRM record is untouched.
 *
 * Archiving a customer deactivates their login, and restoring them
 * deliberately does not bring it back ({@see ArchiveCustomer::restore()}):
 * letting somebody sign in again is a separate decision from correcting a
 * record. This is that decision, behind `customer.account.manage`.
 *
 * An archived customer's login cannot be switched back on — restore the record
 * first, or the result is a person who can sign in and whom staff cannot see.
 *
 * Nothing about the credential changes: no password, no token, no phone
 * verification (ADR-040). A deactivated account simply fails
 * `canAuthenticate()`, which is what both the sign-in Action and the API token
 * guard ask. Audited without PII — the customer's name is the label, as in
 * every CRM audit row.
 */
final class SetCustomerAccountStatus
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(Customer $customer, bool $active, User $actingUser): CustomerAccount
    {
        if (! $actingUser->hasPermission(Permission::CustomerAccountManage)) {
            throw new AuthorizationException(__('manager_customers.errors.may_not_manage_accounts'));
        }

        if ($active && $customer->isArchived()) {
            throw ValidationException::withMessages(['account' => __('manager_customers.errors.restore_before_login')]);
        }

        /** @var array{0: CustomerAccount, 1: bool} $result */
        $result = DB::connection('tenant')->transaction(function () use ($customer, $active): array {
            /** @var CustomerAccount|null $account */
            $account = CustomerAccount::query()
                ->where('customer_id', $customer->getKey())
                ->lockForUpdate()
                ->first();

            if (! $account instanceof CustomerAccount) {
                throw ValidationException::withMessages(['account' => __('manager_customers.errors.no_account')]);
            }

            if ($account->is_active === $active) {
                return [$account, false];
            }

            $account->forceFill(['is_active' => $active])->save();

            return [$account, true];
        });

        [$account, $changed] = $result;

        if ($changed) {
            $this->audit->record(new AuditEvent(
                action: $active ? 'crm.customer_account.activated' : 'crm.customer_account.deactivated',
                category: AuditCategory::Security,
                actor: Actor::staff($actingUser),
                targetType: Customer::class,
                targetId: $customer->uuid,
                targetLabel: $customer->name,
                severity: $active ? AuditSeverity::Notice : AuditSeverity::Warning,
                before: ['is_active' => ! $active],
                after: ['is_active' => $active],
            ));
        }

        return $account;
    }
}
