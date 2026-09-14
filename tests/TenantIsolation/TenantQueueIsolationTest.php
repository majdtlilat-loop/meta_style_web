<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\TenantConnectionGuard;
use Illuminate\Support\Facades\DB;
use Tests\Support\RecordTenantMarkerJob;

/*
|--------------------------------------------------------------------------
| Queue tenant context
|--------------------------------------------------------------------------
|
| Isolation cases 8 and 9 (docs/11-TESTING-STRATEGY.md §4).
|
| These run against the real `database` queue driver, not `sync`. That matters:
| `sync` executes inline, with the dispatching tenant still bound, so it would
| pass without proving anything. Only a serialise → store → dequeue →
| deserialise round trip shows that the job restores the tenant it was
| dispatched under.
|
| The worker is given a raised memory ceiling. `queue:work` stops itself once
| the PROCESS exceeds --memory (128 MB by default), and by the time a full
| suite run reaches these tests the PHP process is well past that — so the
| worker would exit after a single job and the second one would sit unprocessed.
| That is an artifact of running a worker inside the test process, not a
| property of the queue.
|
| Note the closure style below: `dispatch()` returns a PendingDispatch that
| queues the job in its DESTRUCTOR. Returning it from the tenant closure (an
| arrow function does exactly that) would destruct it after tenancy had already
| ended, and the job would be queued with no tenant attached. Application code
| has to be careful about this too.
|
*/

/**
 * @return array{marker: string, ran_as: string}|null
 */
function jobMarker(): ?array
{
    $value = DB::connection(TenantConnectionGuard::CONNECTION)
        ->table('settings')
        ->where('key', 'job_marker')
        ->value('value');

    if ($value === null) {
        return null;
    }

    /** @var array{marker: string, ran_as: string} $decoded */
    $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('carries the dispatching tenant in the queued payload', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->asTenant($alpha, function (): void {
        RecordTenantMarkerJob::dispatch('alpha-marker');
    });

    $payload = DB::connection('control')->table('jobs')->value('payload');

    expect($payload)->toBeString();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['tenant_id'] ?? null)->toBe($alpha->id);
});

it('executes a queued job as the tenant it was dispatched under', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $this->asTenant($alpha, function (): void {
        RecordTenantMarkerJob::dispatch('for-alpha');
    });

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true, '--memory' => 4096])->run();

    $fromAlpha = $this->asTenant($alpha, fn (): ?array => jobMarker());
    $fromBeta = $this->asTenant($beta, fn (): ?array => jobMarker());

    expect($fromAlpha)->not->toBeNull()
        ->and($fromAlpha['marker'])->toBe('for-alpha')
        ->and($fromAlpha['ran_as'])->toBe($alpha->id)
        // The write landed in Alpha's database, not merely "believed" it was
        // Alpha. Beta's database is untouched.
        ->and($fromBeta)->toBeNull();
});

it('does not let a job run as the wrong tenant when several are queued', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $this->asTenant($alpha, function (): void {
        RecordTenantMarkerJob::dispatch('alpha-job');
    });
    $this->asTenant($beta, function (): void {
        RecordTenantMarkerJob::dispatch('beta-job');
    });

    $queued = DB::connection('control')->table('jobs')->count();

    $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 4096])->run();

    $remaining = DB::connection('control')->table('jobs')->count();

    // Surfaces the reason rather than leaving a bare "expected 'beta-job', got
    // null" when a job dies in, or is skipped by, the worker.
    expect(DB::connection('control')->table('failed_jobs')->pluck('exception')->all())->toBe([])
        ->and([$queued, $remaining])->toBe([2, 0]);

    $fromAlpha = $this->asTenant($alpha, fn (): ?array => jobMarker());
    $fromBeta = $this->asTenant($beta, fn (): ?array => jobMarker());

    expect($fromAlpha['marker'] ?? null)->toBe('alpha-job')
        ->and($fromAlpha['ran_as'] ?? null)->toBe($alpha->id)
        ->and($fromBeta['marker'] ?? null)->toBe('beta-job')
        ->and($fromBeta['ran_as'] ?? null)->toBe($beta->id);
});

it('clears tenant context after the worker finishes', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->asTenant($alpha, function (): void {
        RecordTenantMarkerJob::dispatch('cleanup-check');
    });

    $this->artisan('queue:work', ['--stop-when-empty' => true, '--memory' => 4096])->run();

    // A worker is long-running: a tenant left bound after one job becomes the
    // tenant of the next, unrelated job.
    expect(app(TenantContext::class)->isBound())->toBeFalse()
        ->and(app(TenantConnectionGuard::class)->isInitialized())->toBeFalse()
        ->and(config('database.default'))->toBe('control');
});

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
