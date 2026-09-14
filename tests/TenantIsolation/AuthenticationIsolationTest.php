<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Authentication isolation
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-027 and docs/11-TESTING-STRATEGY.md §4.
|
| A credential issued by one center must be inert everywhere else. These are
| release-gate tests: a failure here means one business can act as another.
|
*/

it('issues a token bound to the center that created it', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');

    $token = $this->apiTokenFor($alpha['tenant']);
    $parsed = TenantApiToken::parse($token);

    expect($parsed)->not->toBeNull()
        ->and($parsed['key'])->toBe($this->publicKeyOf($alpha['tenant']));
});

it('authenticates a staff token against its own center', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');

    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($alpha['tenant'])))
        ->getJson('/api/v1/tenant/me')
        ->assertOk()
        ->assertJsonPath('data.center.key', $alpha['tenant']->id)
        ->assertJsonPath('data.user.is_owner', true);
});

it('rejects a Tenant A token presented for Tenant B', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    // Take Alpha's Sanctum half and stitch it onto Beta's public key: the
    // shape a forged cross-tenant token would take.
    $alphaToken = $this->apiTokenFor($alpha['tenant']);
    $parsed = TenantApiToken::parse($alphaToken);

    $forged = TenantApiToken::format($this->publicKeyOf($beta['tenant']), $parsed['token']);

    // Beta's database has no such token row, so there is nothing to
    // authenticate against — the binding is structural, not a check.
    $this->withHeaders($this->tokenHeaders($forged))
        ->getJson('/api/v1/tenant/me')
        ->assertUnauthorized();
});

it('rejects a bare Sanctum token with no center prefix', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');

    $parsed = TenantApiToken::parse($this->apiTokenFor($alpha['tenant']));

    // Stripping the prefix removes the only tenant signal, so no tenant
    // resolves and the request cannot proceed.
    $this->withHeaders($this->tokenHeaders($parsed['token']))
        ->getJson('/api/v1/tenant/me')
        ->assertNotFound();
});

it('rejects and audits a host that disagrees with the token', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    // Give Beta a host, then present Alpha's token against it.
    TenantModel::query()->findOrFail($beta['tenant']->id)
        ->domains()->create(['domain' => 'beta.metastyle.test', 'is_primary' => true]);

    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($alpha['tenant'])))
        ->getJson('http://beta.metastyle.test/api/v1/tenant/me')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TENANT.RESOLUTION_CONFLICT');

    $entry = PlatformAuditLog::query()->where('action', 'tenancy.resolution.conflict')->first();

    // Two trusted sources naming different tenants is never resolved by
    // preferring one — it is refused and recorded.
    expect($entry)->not->toBeNull()
        ->and($entry->severity)->toBe('critical')
        ->and($entry->category)->toBe('security');
});

it('rejects a token whose row was deleted', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $token = $this->apiTokenFor($alpha['tenant']);

    $this->asCenter($alpha['tenant'], function (): void {
        DB::connection('tenant')->table('personal_access_tokens')->delete();
    });

    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/tenant/me')
        ->assertUnauthorized();
});

it('rejects a deactivated user even with a valid token', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $token = $this->apiTokenFor($alpha['tenant']);

    $this->asCenter($alpha['tenant'], function (): void {
        User::query()->where('is_owner', true)->update(['is_active' => false]);
    });

    // The token is still cryptographically valid. Access is withdrawn the
    // moment the account is, not whenever the token happens to expire.
    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/tenant/me')
        ->assertUnauthorized();
});

it('does not leak authentication state between sequential tenant contexts', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $alphaOwner = $this->ownerOf($alpha['tenant']);
    $betaOwner = $this->ownerOf($beta['tenant']);

    // Two different databases, two different owners — the ids may well
    // collide, which is precisely why identity must never be assumed to
    // survive a context switch.
    expect($alphaOwner->uuid)->not->toBe($betaOwner->uuid)
        ->and($alphaOwner->email)->toBe('owner@alpha.test')
        ->and($betaOwner->email)->toBe('owner@beta.test');

    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('rate limits login attempts per center as well as per address', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $key = $this->publicKeyOf($alpha['tenant']);

    $payload = ['center_key' => $key, 'identifier' => 'owner@alpha.test', 'password' => 'wrong-password'];

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson('/api/v1/public/auth/token', $payload)->assertUnauthorized();
    }

    // Brute force against one center is stopped, and the bucket is keyed by
    // center so it cannot be spread quietly across the platform.
    $this->postJson('/api/v1/public/auth/token', $payload)->assertStatus(429);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
    $this->tearDownTenantDatabases();
});
