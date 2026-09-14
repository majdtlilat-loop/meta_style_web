<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantResolutionConflict;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant resolution
|--------------------------------------------------------------------------
|
| Isolation cases 10 and 11 (docs/11-TESTING-STRATEGY.md §4), and the rules in
| docs/02-TENANCY.md §2.
|
*/

beforeEach(function (): void {
    Route::middleware('tenant')->get('/__test/whoami', fn () => response()->json([
        'tenant' => app(TenantContext::class)->id(),
    ]));
});

it('resolves a tenant from its registered host', function (): void {
    $alpha = $this->provisionTenant('Alpha', ['alpha.metastyle.test']);

    $this->get('http://alpha.metastyle.test/__test/whoami')
        ->assertOk()
        ->assertJsonPath('tenant', $alpha->id);
});

it('keeps two hosts pointing at their own tenants', function (): void {
    $alpha = $this->provisionTenant('Alpha', ['alpha.metastyle.test']);
    $beta = $this->provisionTenant('Beta', ['beta.metastyle.test']);

    $this->get('http://alpha.metastyle.test/__test/whoami')->assertJsonPath('tenant', $alpha->id);
    $this->get('http://beta.metastyle.test/__test/whoami')->assertJsonPath('tenant', $beta->id);
});

it('fails for an unknown host instead of guessing', function (): void {
    $this->provisionTenant('Alpha', ['alpha.metastyle.test']);

    // 404, not 403: a more precise answer would confirm which hosts are
    // registered on the platform.
    $this->getJson('http://unknown.metastyle.test/__test/whoami')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'TENANT.NOT_RESOLVED');
});

it('leaves no tenant bound after an unresolved request', function (): void {
    $this->getJson('http://unknown.metastyle.test/__test/whoami')->assertNotFound();

    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('ignores a tenant id supplied by the client', function (): void {
    $alpha = $this->provisionTenant('Alpha', ['alpha.metastyle.test']);
    $beta = $this->provisionTenant('Beta', ['beta.metastyle.test']);

    // Every shape a caller might try. None of them is a resolution source.
    $response = $this->withHeaders(['X-Tenant' => $beta->id, 'X-Tenant-Id' => $beta->id])
        ->get('http://alpha.metastyle.test/__test/whoami?tenant_id='.$beta->id);

    $response->assertOk()->assertJsonPath('tenant', $alpha->id);
});

it('does not resolve a tenant that is not fully provisioned', function (): void {
    $alpha = $this->provisionTenant('Alpha', ['alpha.metastyle.test']);

    $this->tenantModel($alpha)->forceFill(['provisioning_status' => 'failed'])->save();

    // A half-built tenant must not serve traffic. Failing here is far better
    // than a confusing connection error deeper in the request.
    $this->getJson('http://alpha.metastyle.test/__test/whoami')->assertNotFound();
});

it('refuses to guess when two trusted sources disagree', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    app(StanclTenantResolver::class)->guardAgainstConflict([
        'host' => $alpha,
        'token' => $beta,
    ]);
})->throws(TenantResolutionConflict::class);

it('accepts agreeing sources', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    app(StanclTenantResolver::class)->guardAgainstConflict([
        'host' => $alpha,
        'token' => $alpha,
    ]);

    expect(true)->toBeTrue();
});

it('names both sources and both tenants in the conflict', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    try {
        app(StanclTenantResolver::class)->guardAgainstConflict(['host' => $alpha, 'token' => $beta]);
    } catch (TenantResolutionConflict $e) {
        expect($e->firstSource)->toBe('host')
            ->and($e->firstTenantId)->toBe($alpha->id)
            ->and($e->secondSource)->toBe('token')
            ->and($e->secondTenantId)->toBe($beta->id);

        return;
    }

    $this->fail('Expected TenantResolutionConflict.');
});

/*
 * The conflict path through a real request now lives in
 * AuthenticationIsolationTest — Phase 3's token binding provides the second
 * trusted source, so a host/token disagreement can be provoked genuinely
 * instead of through a substituted resolver. The rule itself stays tested
 * directly above, because it must keep holding for every source added later.
 */

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
