<?php

declare(strict_types=1);

use App\Kernel\Diagnostics\ProductionReadiness;
use App\Kernel\Diagnostics\ReadinessCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Production readiness
|--------------------------------------------------------------------------
|
| docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §11 · docs/08-AUDIT-SECURITY.md §13.
|
| Every condition checked here fails silently. The site serves traffic, the
| suite is green, and something is quietly not doing its job — most sharply the
| rate limiter, which on a per-process store still returns 200s and 429s and
| still looks exactly like a working limiter while allowing N times the
| published limit.
|
| The local and test environments deliberately do NOT require Redis. That is
| the point of the environment split, and these tests hold it: the same
| configuration that is fine locally must be refused in production.
|
*/

/**
 * @return array<string, ReadinessCheck>
 */
function checksByName(bool $production): array
{
    $indexed = [];

    foreach (app(ProductionReadiness::class)->checks($production) as $check) {
        $indexed[$check->name] = $check;
    }

    return $indexed;
}

it('passes with the local and test configuration as shipped', function (): void {
    $readiness = app(ProductionReadiness::class);

    // No Redis, no shared cache, nothing extra to install to run the suite.
    expect($readiness->passes(false))->toBeTrue()
        ->and($readiness->failures(false))->toBe([]);
});

it('refuses a per-process rate limit store in production', function (): void {
    config()->set('cache.default', 'array');
    config()->set('cache.limiter', null);

    $check = checksByName(true)['rate limit backend'];

    // The failure this exists for: N workers, N private counters, and a
    // published limit of 5/min that actually admits 5N.
    expect($check->isFailure())->toBeTrue()
        ->and($check->detail)->toContain('not shared between processes')
        ->and($check->remedy)->toContain('redis');
});

it('refuses a per-server rate limit store in production', function (): void {
    config()->set('cache.default', 'file');

    expect(checksByName(true)['rate limit backend']->isFailure())->toBeTrue();
});

it('accepts Redis for rate limiting in production', function (): void {
    config()->set('cache.default', 'redis');

    $checks = checksByName(true);

    expect($checks['rate limit backend']->isOk())->toBeTrue()
        ->and($checks['cache tags']->isOk())->toBeTrue();
});

it('accepts a shared database store for rate limiting but warns about it', function (): void {
    config()->set('cache.default', 'database');

    $checks = checksByName(true);

    // Genuinely shared across processes, so not a failure — saying otherwise
    // would be dishonest. Slower than Redis, so not silent either.
    expect($checks['rate limit backend']->isFailure())->toBeFalse()
        ->and($checks['rate limit backend']->isWarning())->toBeTrue()
        // But the database store cannot do tags, and tenant cache isolation is
        // tag-based. Together the two checks leave Redis as the only
        // configuration that satisfies both (ADR-025).
        ->and($checks['cache tags']->isFailure())->toBeTrue();
});

it('honours an explicit limiter store over the default store', function (): void {
    config()->set('cache.default', 'array');
    config()->set('cache.limiter', 'redis');

    // Laravel lets the rate limiter use a different store from the rest of the
    // cache. A check that only read cache.default would report a failure that
    // is not there.
    expect(checksByName(true)['rate limit backend']->isOk())->toBeTrue();
});

it('checks the store the rate limiter genuinely uses', function (): void {
    $store = config('cache.limiter') ?? config('cache.default');

    RateLimiter::hit('metastyle-readiness-probe', 60);

    expect(RateLimiter::attempts('metastyle-readiness-probe'))->toBe(1);

    Cache::store($store)->flush();

    // Clearing the store the check inspects cleared the limiter's counter, so
    // the two are the same store. Without this, the whole check could be
    // reading a config key nothing uses and reporting confidently on nothing.
    expect(RateLimiter::attempts('metastyle-readiness-probe'))->toBe(0);
});

it('refuses an inline or discarding queue in production', function (): void {
    config()->set('queue.default', 'sync');

    // Provisioning creates a database and runs migrations. Inline, that is the
    // registration request, and it times out.
    expect(checksByName(true)['queue driver']->isFailure())->toBeTrue();

    config()->set('queue.default', 'null');

    expect(checksByName(true)['queue driver']->isFailure())->toBeTrue();
});

it('refuses debug mode and an empty key in production', function (): void {
    config()->set('app.debug', true);

    expect(checksByName(true)['debug mode']->isFailure())->toBeTrue();

    config()->set('app.key', '');

    // Without a key the encrypted bootstrap credential (ADR-031) can neither
    // be written nor read, so registration itself is broken.
    expect(checksByName(true)['application key']->isFailure())->toBeTrue();
});

it('warns about file sessions in production without failing a single-server deployment', function (): void {
    config()->set('session.driver', 'file');

    $check = checksByName(true)['session driver'];

    // One app server: fine. Two: a center is signed out whenever a request
    // lands on the other one, and on web routes the session is what names
    // their tenant (ADR-030).
    expect($check->isWarning())->toBeTrue()
        ->and($check->isFailure())->toBeFalse();

    config()->set('session.driver', 'array');

    expect(checksByName(true)['session driver']->isFailure())->toBeTrue();
});

it('reports middleware ordering as part of readiness', function (): void {
    expect(checksByName(true)['middleware order']->isOk())->toBeTrue();
});

it('exits non-zero from metastyle:doctor when production rules are broken', function (): void {
    config()->set('cache.default', 'array');
    config()->set('app.debug', true);

    $this->artisan('metastyle:doctor', ['--production' => true])->assertFailed();
});

it('exits zero from metastyle:doctor on a sound production configuration', function (): void {
    config()->set('cache.default', 'redis');
    config()->set('cache.limiter', null);
    config()->set('queue.default', 'redis');
    config()->set('session.driver', 'redis');
    config()->set('app.debug', false);

    $this->artisan('metastyle:doctor', ['--production' => true])->assertSuccessful();
});

it('exits zero from metastyle:doctor in the local environment', function (): void {
    $this->artisan('metastyle:doctor')->assertSuccessful();
});
