<?php

declare(strict_types=1);

use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use Illuminate\Cache\TaggableStore;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Storage and cache isolation
|--------------------------------------------------------------------------
|
| Isolation cases 6 and 7 (docs/11-TESTING-STRATEGY.md §4).
|
| Database isolation is only part of the job. Files and cache entries leak just
| as badly and are far easier to get wrong, because nothing about them looks
| tenant-specific at the call site.
|
*/

it('stores each tenant\'s files under its own namespace', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $store = app(MediaStore::class);

    $alphaPath = $this->asTenant(
        $alpha,
        fn (): string => $store->put(MediaCollection::Branding, 'ALPHA-LOGO', 'png'),
    );

    $betaPath = $this->asTenant(
        $beta,
        fn (): string => $store->put(MediaCollection::Branding, 'BETA-LOGO', 'png'),
    );

    // Each tenant reads back only its own file.
    expect($this->asTenant($alpha, fn (): ?string => $store->get(MediaCollection::Branding, $alphaPath)))
        ->toBe('ALPHA-LOGO')
        ->and($this->asTenant($beta, fn (): ?string => $store->get(MediaCollection::Branding, $betaPath)))
        ->toBe('BETA-LOGO');

    // And cannot reach the other's, even holding the exact path.
    expect($this->asTenant($beta, fn (): bool => $store->exists(MediaCollection::Branding, $alphaPath)))
        ->toBeFalse()
        ->and($this->asTenant($alpha, fn (): bool => $store->exists(MediaCollection::Branding, $betaPath)))
        ->toBeFalse();
});

it('resolves different absolute storage roots per tenant', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $store = app(MediaStore::class);

    $alphaRoot = $this->asTenant(
        $alpha,
        fn (): string => $store->absolutePath(MediaCollection::Branding, 'probe.txt'),
    );

    $betaRoot = $this->asTenant(
        $beta,
        fn (): string => $store->absolutePath(MediaCollection::Branding, 'probe.txt'),
    );

    expect($alphaRoot)->not->toBe($betaRoot)
        ->and(str_replace('\\', '/', $alphaRoot))->toContain('tenants/'.$alpha->id)
        ->and(str_replace('\\', '/', $betaRoot))->toContain('tenants/'.$beta->id);
});

it('refuses to touch storage with no tenant bound', function (): void {
    // Without this, a write with no tenant would land in the shared storage
    // root, where the next tenant could read it.
    app(MediaStore::class)->put(MediaCollection::Branding, 'orphan', 'txt');
})->throws(TenantNotResolved::class);

it('keeps the same logical cache key separate per tenant', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $this->asTenant($alpha, function () use ($alpha, $beta): void {
        Cache::put('dashboard_summary', 'ALPHA-VALUE', 60);

        // The tenant reads its own value back through the plain facade call.
        expect(Cache::get('dashboard_summary'))->toBe('ALPHA-VALUE');

        // The value is stored under THIS tenant's tag...
        expect(Cache::store()->tags(['tenant'.$alpha->id])->get('dashboard_summary'))
            ->toBe('ALPHA-VALUE');

        // ...and the identical key under another tenant's tag is a miss. This
        // is the non-collision guarantee, asserted against the mechanism that
        // provides it rather than inferred from an absence.
        expect(Cache::store()->tags(['tenant'.$beta->id])->get('dashboard_summary'))
            ->toBeNull();
    });
});

it('does not expose a tenant cache entry to the control plane', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->asTenant($alpha, fn () => Cache::put('tenant_only_key', 'secret', 60));

    expect(Cache::get('tenant_only_key'))->toBeNull();
});

it('uses a taggable cache store, without which isolation would be silent', function (): void {
    // Tenant cache isolation works by tagging. The `file` and `database` stores
    // do not support tags; configuring one would leave every tenant sharing a
    // keyspace. Laravel throws on tags() for those stores, so the failure is at
    // least loud — this assertion catches it at the config level instead
    // (ADR-025).
    expect(Cache::store()->getStore())->toBeInstanceOf(TaggableStore::class);
});

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
