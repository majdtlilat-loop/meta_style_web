<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Loyalty\Application\Actions\ConfigureLoyalty;
use App\Modules\Loyalty\Domain\Models\LoyaltyAccount;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Memberships\Application\Actions\ManageMembershipPlan;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Packages\Application\Actions\ManagePackageDefinition;
use App\Modules\Packages\Domain\Models\CustomerPackage;
use App\Modules\Packages\Domain\Models\PackageDefinition;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\CreateDraftSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Programs, packages and customer sales for the Phase 11 tests.
 *
 * ## What the plan owns, and what a test must buy
 *
 * No seeded plan sells `loyalty`, `memberships` or `packages`: every test that
 * uses one grants it, so "a downgrade keeps what the customer paid for" is a
 * real assertion — revoke, then check (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md
 * §22).
 */
trait SeedsBenefits
{
    protected function grantLoyalty(?string $tenantId = null): void
    {
        $this->grantEntitlement('loyalty', $tenantId);
    }

    protected function grantPackages(?string $tenantId = null): void
    {
        $this->grantEntitlement('packages', $tenantId);
    }

    protected function grantMemberships(?string $tenantId = null): void
    {
        $this->grantEntitlement('memberships', $tenantId);
    }

    /**
     * Customer logins — for the customer's own benefits over the API.
     */
    protected function grantCustomerAccounts(?string $tenantId = null): void
    {
        $this->grantEntitlement('customer_accounts', $tenantId);
    }

    /**
     * For tests that travel months ahead. The seeded trial lasts 21 days; past
     * it the center is read-only and every Action refuses — for a reason that
     * has nothing to do with the rule under test. So the trial is made to
     * outlast the test.
     */
    protected function outlastTrial(int $days = 365, ?string $tenantId = null): void
    {
        $tenantId ??= app(TenantContext::class)->id();

        Subscription::query()->where('tenant_id', $tenantId)
            ->update(['trial_ends_at' => CarbonImmutable::now()->utc()->addDays($days)]);

        app(Entitlements::class)->invalidate((string) $tenantId);
    }

    /**
     * The loyalty rules, set through the real Action — so a rule VERSION is
     * written, effective from now, as a manager changing them would. By
     * default: 1 point per 1,000 IQD collected, a point worth 100 IQD, no
     * visit reward, no expiry.
     *
     * @param  array<string, int|null>  $rules
     */
    protected function loyaltyProgram(User $user, array $rules = []): LoyaltyProgram
    {
        /** @var array{spend_points: int, spend_unit_minor: int, min_spend_minor: int, visit_points: int, point_value_minor: int, min_redeem_points: int, expiry_days: int|null} $merged */
        $merged = [
            'spend_points' => 1,
            'spend_unit_minor' => 1000,
            'min_spend_minor' => 0,
            'visit_points' => 0,
            'point_value_minor' => 100,
            'min_redeem_points' => 1,
            'expiry_days' => null,
            ...$rules,
        ];

        return app(ConfigureLoyalty::class)($user, $merged);
    }

    /**
     * A draft sale for a customer, by a user whose shift is open at the branch.
     */
    protected function customerSale(Branch $branch, User $user, Customer $customer): Sale
    {
        if (CashierShift::query()->where('active_user_id', $user->getKey())->where('branch_id', $branch->id)->doesntExist()) {
            $this->openShift($branch, $user);
        }

        return app(CreateDraftSale::class)($branch->uuid, $user, $customer->uuid, (string) Str::uuid());
    }

    /**
     * A finalized invoice for the customer: one seeded service (20,000 IQD).
     *
     * @param  array<string, mixed>  $seed
     */
    protected function customerInvoice(array $seed, User $user, Customer $customer): Invoice
    {
        $sale = $this->customerSale($seed['branch'], $user, $customer);
        app(AddSaleLine::class)($sale, $user, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        return app(FinalizeSale::class)($sale, $user)->invoice;
    }

    /**
     * A package of `$sessions` of the seeded service, through the real Action.
     *
     * @param  array<string, mixed>  $seed
     */
    protected function packageDefinition(array $seed, User $user, int $sessions = 5, int $priceMinor = 80000, int $validityDays = 90): PackageDefinition
    {
        return app(ManagePackageDefinition::class)->save($user, ['en' => $sessions.' haircuts'], $priceMinor, $validityDays, [
            ['service' => $seed['service']->uuid, 'quantity' => $sessions],
        ]);
    }

    /**
     * A finalized invoice selling one package to the customer.
     */
    protected function packageInvoice(Branch $branch, User $user, Customer $customer, PackageDefinition $definition): Invoice
    {
        $sale = $this->customerSale($branch, $user, $customer);
        app(AddSaleLine::class)($sale, $user, ['kind' => 'offering', 'offering_type' => 'package', 'offering' => $definition->uuid]);

        return app(FinalizeSale::class)($sale, $user)->invoice;
    }

    /**
     * The customer's package, sold and paid for in full — so it is active.
     *
     * @param  array<string, mixed>  $seed
     */
    protected function paidPackage(array $seed, User $user, Customer $customer, int $priceMinor = 80000): CustomerPackage
    {
        $invoice = $this->packageInvoice($seed['branch'], $user, $customer, $this->packageDefinition($seed, $user, priceMinor: $priceMinor));

        if ($priceMinor > 0) {
            app(CollectDeskPayment::class)($invoice, $user, PaymentMethod::Cash, $priceMinor);
        }

        /** @var CustomerPackage $package */
        $package = CustomerPackage::query()->where('customer_id', $customer->getKey())->latest('id')->firstOrFail();

        return $package;
    }

    /**
     * A plan through the real Action. By default: 20% off the seeded service,
     * unlimited, 30 days, 50,000 IQD.
     *
     * @param  array<string, mixed>  $seed
     * @param  list<array{service?: string|null, discount_type: string, basis_points?: int|null, amount_minor?: int|null, uses_per_term?: int|null}>|null  $benefits
     */
    protected function membershipPlan(array $seed, User $user, ?array $benefits = null, int $priceMinor = 50000, int $durationDays = 30): MembershipPlan
    {
        return app(ManageMembershipPlan::class)->save($user, ['en' => 'Gold'], $priceMinor, $durationDays, $benefits ?? [
            ['service' => $seed['service']->uuid, 'discount_type' => 'percent', 'basis_points' => 2000],
        ]);
    }

    /**
     * A finalized invoice selling one membership to the customer.
     */
    protected function membershipInvoice(Branch $branch, User $user, Customer $customer, MembershipPlan $plan): Invoice
    {
        $sale = $this->customerSale($branch, $user, $customer);
        app(AddSaleLine::class)($sale, $user, ['kind' => 'offering', 'offering_type' => 'membership', 'offering' => $plan->uuid]);

        return app(FinalizeSale::class)($sale, $user)->invoice;
    }

    /**
     * A draft for the customer with one line of the seeded service, and that
     * line's uuid.
     *
     * @param  array<string, mixed>  $seed
     * @param  list<string>  $addons
     * @return array{0: Sale, 1: string}
     */
    protected function serviceDraft(array $seed, User $user, Customer $customer, int $quantity = 1, array $addons = []): array
    {
        $sale = $this->customerSale($seed['branch'], $user, $customer);
        app(AddSaleLine::class)($sale, $user, ['kind' => 'service', 'service' => $seed['service']->uuid, 'quantity' => $quantity, 'addons' => $addons]);

        $line = (string) SaleItem::query()->where('sale_id', $sale->getKey())->orderByDesc('id')->value('uuid');

        return [$sale->fresh() ?? $sale, $line];
    }

    protected function loyaltyAccountOf(Customer $customer): ?LoyaltyAccount
    {
        /** @var LoyaltyAccount|null $account */
        $account = LoyaltyAccount::query()->where('customer_id', $customer->getKey())->first();

        return $account;
    }

    private function grantEntitlement(string $key, ?string $tenantId): void
    {
        $tenantId ??= app(TenantContext::class)->id();

        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'entitlement' => $key],
            ['mode' => OverrideMode::Grant, 'reason' => 'set in a Phase 11 test', 'expires_at' => null],
        );

        app(Entitlements::class)->invalidate((string) $tenantId);
    }
}
