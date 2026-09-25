<?php

declare(strict_types=1);

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\TenantApiToken;

/*
|--------------------------------------------------------------------------
| Tenant API surface
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 3 Part M. Exercises the endpoints end to end,
| through real tenant resolution and real authentication.
|
*/

it('issues a token from a center key and credentials', function (): void {
    $center = $this->registerCenter('Alpha', 'owner@alpha.test', 'correct-horse-battery-staple');

    $response = $this->postJson('/api/v1/public/auth/token', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'identifier' => 'owner@alpha.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertCreated();

    $token = (string) $response->json('data.token');

    // The token carries the center's public key, which is what lets it find
    // its way home on the next request.
    expect(TenantApiToken::parse($token))->not->toBeNull()
        ->and($response->json('data.expires_at'))->toBeString();

    $this->withHeaders($this->tokenHeaders($token))->getJson('/api/v1/tenant/me')->assertOk();
});

it('refuses the same credentials against a different center', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    // Alpha's owner, Beta's center key.
    $this->postJson('/api/v1/public/auth/token', [
        'center_key' => $this->publicKeyOf($beta['tenant']),
        'identifier' => 'owner@alpha.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertUnauthorized()->assertJsonPath('error.code', 'AUTH.INVALID_CREDENTIALS');

    unset($alpha);
});

it('answers an unknown center key exactly like bad credentials', function (): void {
    $center = $this->registerCenter();

    $unknown = $this->postJson('/api/v1/public/auth/token', [
        'center_key' => 'ctr_'.str_repeat('a', 32),
        'identifier' => 'owner@alpha.test',
        'password' => 'correct-horse-battery-staple',
    ]);

    $wrongPassword = $this->postJson('/api/v1/public/auth/token', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'identifier' => 'owner@alpha.test',
        'password' => 'definitely-not-the-password',
    ]);

    // Identical answers, or the endpoint becomes a way to discover which
    // center keys are real.
    expect($unknown->status())->toBe($wrongPassword->status())
        ->and($unknown->json('error.code'))->toBe($wrongPassword->json('error.code'));
});

it('refuses a staff account that has never been activated', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        User::query()->create([
            'name' => 'Pending',
            'email' => 'pending@alpha.test',
            'password' => null,
            'is_active' => true,
        ]);
    });

    $this->postJson('/api/v1/public/auth/token', [
        'center_key' => $this->publicKeyOf($center['tenant']),
        'identifier' => 'pending@alpha.test',
        'password' => 'anything-at-all-here',
    ])->assertUnauthorized();
});

it('returns the caller\'s permissions, scope and entitlements', function (): void {
    $center = $this->registerCenter();

    $response = $this->withHeaders($this->tokenHeaders($this->apiTokenFor($center['tenant'])))
        ->getJson('/api/v1/tenant/me')
        ->assertOk();

    expect($response->json('data.permissions'))->toEqualCanonicalizing(Permission::codes())
        ->and($response->json('data.branch_scope.all_branches'))->toBeTrue()
        ->and($response->json('data.entitlements'))->toContain('booking')
        ->and($response->json('data.access_level'))->toBe('full')
        // The public key, never the internal sequence.
        ->and($response->json('data.center'))->not->toHaveKey('sequence');
});

it('lists and creates employees through the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $this->withHeaders($headers)->getJson('/api/v1/tenant/employees')
        ->assertOk()
        ->assertJsonPath('data', []);

    $created = $this->withHeaders($headers)->postJson('/api/v1/tenant/employees', [
        'name' => ['en' => 'Sara', 'ar' => 'سارة'],
        'email' => 'sara@alpha.test',
        'phone' => '+9647701230011',
    ])->assertCreated();

    expect($created->json('data.has_login'))->toBeTrue()
        ->and($created->json('data.activation_token'))->toBeString();

    $this->withHeaders($headers)->getJson('/api/v1/tenant/employees')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Sara')
        ->assertJsonPath('data.0.status', 'active');
});

it('refuses employee management without the permission', function (): void {
    $center = $this->registerCenter();

    $cashier = $this->asCenter($center['tenant'], function (): User {
        $role = Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail();

        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Cashier',
            'email' => 'cashier@alpha.test',
            'password' => 'a-real-password-here-99',
            'is_active' => true,
        ]);
        $user->roles()->sync([$role->id]);

        return $user;
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant'], $cashier));

    $this->withHeaders($headers)->getJson('/api/v1/tenant/employees')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PERMISSION.DENIED');

    $this->withHeaders($headers)->postJson('/api/v1/tenant/employees', ['name' => ['en' => 'X']])
        ->assertForbidden();
});

it('exposes roles with the permission catalog', function (): void {
    $center = $this->registerCenter();

    $response = $this->withHeaders($this->tokenHeaders($this->apiTokenFor($center['tenant'])))
        ->getJson('/api/v1/tenant/roles')
        ->assertOk();

    expect($response->json('data.roles'))->toHaveCount(5)
        // The catalog is served rather than hardcoded client-side, so a client
        // cannot drift from the server's idea of what exists.
        ->and($response->json('data.catalog'))->toHaveCount(count(Permission::cases()));
});

it('deactivates an employee through the API and revokes their access', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $created = $this->withHeaders($headers)->postJson('/api/v1/tenant/employees', [
        'name' => ['en' => 'Sara'],
        'email' => 'sara@alpha.test',
        'phone' => '+9647701230012',
    ])->assertCreated();

    $this->withHeaders($headers)
        ->patchJson('/api/v1/tenant/employees/'.$created->json('data.uuid').'/status', ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $this->asCenter($center['tenant'], function (): void {
        expect(User::query()->where('email', 'sara@alpha.test')->firstOrFail()->is_active)->toBeFalse();
    });
});

it('requires authentication for tenant endpoints', function (): void {
    $center = $this->registerCenter();

    // No token at all: no tenant resolves either, so this is a 404 rather than
    // a 401 — the host is not a center.
    $this->getJson('/api/v1/tenant/me')->assertNotFound();

    unset($center);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
