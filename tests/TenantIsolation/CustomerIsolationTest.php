<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteVisibility;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Customers\Application\Actions\RegisterCustomerAccount;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Customer isolation and the staff/customer authentication boundary
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §4 — release gate.
|
| Customers are the first major PII domain, and customer accounts are the first
| non-staff identity. Two properties are load-bearing: a customer never crosses
| a center boundary, and a customer credential is never a staff credential.
|
*/

/** Grants `customer_accounts` to a center under test. */
function grantAccountsTo(string $tenantId): void
{
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $tenantId, 'entitlement' => 'customer_accounts'],
        ['mode' => 'grant', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
    );

    app(Entitlements::class)->invalidate($tenantId);
}

it('keeps the same real person as two independent customers in two centers', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], fn () => $this->seedCustomer('Sara Ahmed', '0750 123 4567'));
    $this->asCenter($beta['tenant'], fn () => $this->seedCustomer('Sara A.', '0750 123 4567'));

    // The same number, deliberately. Each center has its own database and its
    // own unique index; neither may learn the other exists, and there is no
    // cross-center deduplication anywhere (Phase 5 §1).
    $inAlpha = $this->asCenter($alpha['tenant'], fn (): array => Customer::query()->pluck('name')->all());
    $inBeta = $this->asCenter($beta['tenant'], fn (): array => Customer::query()->pluck('name')->all());

    expect($inAlpha)->toBe(['Sara Ahmed'])
        ->and($inBeta)->toBe(['Sara A.']);
});

it('keeps customer accounts, tags and notes inside their own center', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    grantAccountsTo($alpha['tenant']->id);

    $this->asCenter($alpha['tenant'], function (): void {
        $result = app(RegisterCustomerAccount::class)('0750 123 4567', 'a-strong-password', 'Sara');

        $result['customer']->addInternalNote('Alpha only note.', null, NoteVisibility::Internal);

        CustomerTag::query()->create([
            'name' => TranslatedText::make('en', 'VIP'),
            'is_active' => true,
        ]);
    });

    $counts = $this->asCenter($beta['tenant'], fn (): array => [
        'customers' => Customer::query()->count(),
        'accounts' => CustomerAccount::query()->count(),
        'tags' => CustomerTag::query()->count(),
        'notes' => InternalNote::query()->count(),
    ]);

    expect($counts)->toBe(['customers' => 0, 'accounts' => 0, 'tags' => 0, 'notes' => 0]);
});

it('refuses a Tenant A customer token under Tenant B', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    grantAccountsTo($alpha['tenant']->id);

    $token = $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($alpha['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
        'name' => 'Sara',
    ])->assertStatus(201)->json('data.token.token');

    // Works where it was issued.
    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/customer/me')
        ->assertOk()
        ->assertJsonPath('data.customer.name', 'Sara');

    // The test client keeps one application instance across requests, so the
    // guard still holds the user it resolved a moment ago. A real second HTTP
    // request would not. Forgetting the guards restores that
    // (docs/11-TESTING-STRATEGY.md §4, harness caveats).
    $this->app['auth']->forgetGuards();

    // Stitch Alpha's Sanctum half onto Beta's public key: the shape a forged
    // cross-tenant token would take. Beta's database has no such row, so there
    // is nothing to authenticate against — the binding is structural.
    $parsed = TenantApiToken::parse($token);
    $forged = TenantApiToken::format($this->publicKeyOf($beta['tenant']), $parsed['token']);

    $this->withHeaders($this->tokenHeaders($forged))
        ->getJson('/api/v1/customer/me')
        ->assertUnauthorized();
});

it('never lets a customer token reach a staff endpoint', function (): void {
    $center = $this->registerCenter();

    grantAccountsTo($center['tenant']->id);

    $token = $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
        'name' => 'Sara',
    ])->assertStatus(201)->json('data.token.token');

    // Sanctum compares a token's owner against its guard's provider model, so a
    // CustomerAccount cannot satisfy `auth:sanctum` (provider `staff`). A
    // customer is not a member of staff with fewer boxes ticked (Phase 5 §5).
    foreach ([
        '/api/v1/tenant/customers',
        '/api/v1/tenant/me',
        '/api/v1/tenant/employees',
        '/api/v1/tenant/catalog',
    ] as $endpoint) {
        $this->withHeaders($this->tokenHeaders($token))->getJson($endpoint)->assertUnauthorized();
    }
});

it('never lets a staff token reach a customer endpoint', function (): void {
    $center = $this->registerCenter();

    grantAccountsTo($center['tenant']->id);

    $staffToken = $this->apiTokenFor($center['tenant']);

    // The staff token is genuine and its owner is real — it simply is not a
    // customer, and the customer guard resolves against a different provider.
    $this->withHeaders($this->tokenHeaders($staffToken))
        ->getJson('/api/v1/customer/me')
        ->assertUnauthorized();
});

it('refuses a customer token after the account is deactivated', function (): void {
    $center = $this->registerCenter();

    grantAccountsTo($center['tenant']->id);

    $token = $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
        'name' => 'Sara',
    ])->assertStatus(201)->json('data.token.token');

    $this->asCenter($center['tenant'], function (): void {
        CustomerAccount::query()->firstOrFail()->forceFill(['is_active' => false])->save();
    });

    // A token stays cryptographically valid after its owner is deactivated.
    // Access has to be withdrawn the moment the account is, not whenever the
    // token happens to expire.
    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/customer/me')
        ->assertUnauthorized();
});

it('refuses a customer token that has been revoked', function (): void {
    $center = $this->registerCenter();

    grantAccountsTo($center['tenant']->id);

    $token = $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
        'name' => 'Sara',
    ])->assertStatus(201)->json('data.token.token');

    $this->withHeaders($this->tokenHeaders($token))
        ->deleteJson('/api/v1/customer/auth/token')
        ->assertOk();

    // See the note above: one application instance, one cached guard.
    $this->app['auth']->forgetGuards();

    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/customer/me')
        ->assertUnauthorized();
});

it('leaves no tenant bound after a customer request', function (): void {
    $center = $this->registerCenter();

    grantAccountsTo($center['tenant']->id);

    $token = $this->postJson('/api/v1/public/customer/auth/register', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'phone' => '0750 123 4567',
        'password' => 'a-strong-password',
        'name' => 'Sara',
    ])->assertStatus(201)->json('data.token.token');

    $this->withHeaders($this->tokenHeaders($token))->getJson('/api/v1/customer/me')->assertOk();

    $context = app(TenantContext::class);

    expect($context->isBound())->toBeFalse()
        ->and($context->id())->toBeNull();
});

it('cannot reach another center\'s customer through the staff API', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $betaCustomerUuid = $this->asCenter(
        $beta['tenant'],
        fn (): string => $this->seedCustomer('Beta Customer', '0770 111 2222')->uuid,
    );

    // A record in another tenant is indistinguishable from one that does not
    // exist — and unreachable anyway, because the query runs on Alpha's
    // connection (docs/08-AUDIT-SECURITY.md §19).
    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($alpha['tenant'])))
        ->getJson("/api/v1/tenant/customers/{$betaCustomerUuid}")
        ->assertNotFound();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
