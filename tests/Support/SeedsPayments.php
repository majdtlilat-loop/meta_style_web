<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Application\PaymentProviderRegistry;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * Invoices to pay, gateways to pay through, and the entitlements Phase 10 sells.
 *
 * ## What the plan owns, and what a test must buy
 *
 * The seeded trial plan owns `pos` and NOT `payments` or `finance` — the matrix
 * Phase 10 does not change. So a cash test runs with no override, which is what
 * makes "cash needs no online-payments entitlement" a real assertion; every
 * online or finance test grants what it uses (docs/19-PAYMENTS.md §2).
 */
trait SeedsPayments
{
    protected function grantPayments(?string $tenantId = null): void
    {
        $this->overrideEntitlement('payments', OverrideMode::Grant, $tenantId);
    }

    protected function grantFinance(?string $tenantId = null): void
    {
        $this->overrideEntitlement('finance', OverrideMode::Grant, $tenantId);
    }

    /**
     * A finalized invoice for one service at the seeded price (20,000 IQD), by
     * a user whose shift is open at the branch.
     *
     * @param  array<string, mixed>  $seed
     */
    protected function issuedInvoice(array $seed, User $user): Invoice
    {
        $this->ensureShift($seed['branch'], $user);

        $sale = $this->draftSale($seed['branch'], $user);
        app(AddSaleLine::class)($sale, $user, ['kind' => 'service', 'service' => $seed['service']->uuid]);

        return app(FinalizeSale::class)($sale, $user)->invoice;
    }

    /**
     * Today's date where the branch is — the day finance windows are counted in.
     *
     * Never `now()->format('Y-m-d')`: that is the UTC date, and between 21:00 and
     * midnight UTC it is already tomorrow in Baghdad, so a "today" window built
     * from it misses everything that just happened (docs/20-FINANCE.md §42).
     * Defaults to the main branch, whose timezone the all-branch dashboard uses.
     */
    protected function branchToday(?Branch $branch = null): string
    {
        $branch ??= Branch::query()->orderByDesc('is_main')->orderBy('id')->firstOrFail();

        return BranchClock::localDate(CarbonImmutable::now()->utc(), $branch->timezone);
    }

    /**
     * Registers the test-only provider and returns it, so the test can script it.
     */
    protected function fakeGateway(): FakeGatewayProvider
    {
        $fake = new FakeGatewayProvider;

        $this->app->instance(PaymentProviderRegistry::class, $this->app->make(PaymentProviderRegistry::class)->with($fake));

        return $fake;
    }

    /**
     * A configured, enabled TestPay account at the branch — written directly,
     * because configuring one through its Action is what the gateway account
     * tests prove.
     */
    protected function gatewayAccount(Branch $branch, string $provider = FakeGatewayProvider::CODE, bool $enabled = true): GatewayAccount
    {
        /** @var GatewayAccount $account */
        $account = GatewayAccount::query()->create([
            'branch_id' => $branch->id,
            'provider' => $provider,
            'environment' => GatewayEnvironment::Sandbox,
            'display_name' => 'TestPay '.$branch->id,
            'enabled' => $enabled,
            'credentials' => ['client_id' => 'client-test-0001', 'client_secret' => 'secret-test-0001', 'webhook_secret' => 'whsec-test-0001'],
            'safe_identifier' => '••••0001',
            'configured_at' => CarbonImmutable::now()->utc(),
        ]);

        return $account;
    }

    private function ensureShift(Branch $branch, User $user): void
    {
        if (CashierShift::query()
            ->where('active_user_id', $user->getKey())
            ->where('branch_id', $branch->id)
            ->doesntExist()) {
            $this->openShift($branch, $user);
        }
    }

    private function overrideEntitlement(string $key, OverrideMode $mode, ?string $tenantId): void
    {
        $tenantId ??= app(TenantContext::class)->id();

        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'entitlement' => $key],
            ['mode' => $mode, 'reason' => 'set in a Phase 10 test', 'expires_at' => null],
        );

        app(Entitlements::class)->invalidate((string) $tenantId);
    }
}
