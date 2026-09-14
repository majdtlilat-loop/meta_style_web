<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Audit\Redactor;
use App\Kernel\Observability\Middleware\AssignRequestId;
use Tests\Support\CreatesTenantDatabases;

/*
|--------------------------------------------------------------------------
| Audit foundation
|--------------------------------------------------------------------------
|
| docs/08-AUDIT-SECURITY.md. Phase 2 implements the writer and its guarantees,
| not the full catalogue of future events.
|
*/

uses(CreatesTenantDatabases::class);

it('records a platform action when no tenant is bound', function (): void {
    app(Audit::class)->record(new AuditEvent(
        action: 'platform.thing.happened',
        category: AuditCategory::System,
        actor: Actor::system('test'),
    ));

    $entry = PlatformAuditLog::query()->firstOrFail();

    expect($entry->action)->toBe('platform.thing.happened')
        ->and($entry->actor_type)->toBe(ActorType::System->value)
        ->and($entry->source)->toBe(AuditSource::System->value)
        ->and($entry->tenant_id)->toBeNull()
        ->and($entry->occurred_at)->not->toBeNull()
        ->and($entry->uuid)->toBeString();
});

it('records into the tenant\'s own database when a tenant is bound', function (): void {
    $tenant = $this->provisionTenant('Alpha');

    $this->asTenant($tenant, function (): void {
        app(Audit::class)->record(new AuditEvent(
            action: 'tenant.thing.happened',
            category: AuditCategory::Config,
            actor: Actor::system('test'),
        ));
    });

    // The center can see what happened in its own account...
    $inTenant = $this->asTenant(
        $tenant,
        fn (): int => TenantAuditLog::query()->where('action', 'tenant.thing.happened')->count(),
    );

    // ...and it did not land in the platform log.
    expect($inTenant)->toBe(1)
        ->and(PlatformAuditLog::query()->where('action', 'tenant.thing.happened')->count())->toBe(0);
});

it('captures the correlation id so one request is traceable end to end', function (): void {
    $this->withHeader(AssignRequestId::HEADER, '3f2504e0-4f89-41d3-9a0c-0305e82c3301')
        ->getJson('/api/v1/health')
        ->assertOk();

    app(Audit::class)->record(new AuditEvent(
        action: 'platform.correlated',
        category: AuditCategory::System,
        actor: Actor::system('test'),
    ));

    expect(PlatformAuditLog::query()->where('action', 'platform.correlated')->value('correlation_id'))
        ->toBe('3f2504e0-4f89-41d3-9a0c-0305e82c3301');
});

it('redacts secrets before they reach the log', function (): void {
    app(Audit::class)->record(new AuditEvent(
        action: 'platform.credentials.changed',
        category: AuditCategory::Security,
        actor: Actor::system('test'),
        severity: AuditSeverity::Critical,
        before: ['merchant_id' => 'M1', 'secret' => 'old-secret'],
        after: ['merchant_id' => 'M1', 'secret' => 'new-secret'],
        meta: ['db_password' => 'hunter2'],
    ));

    $entry = PlatformAuditLog::query()->firstOrFail();

    expect($entry->before['secret'])->toBe(Redactor::PLACEHOLDER)
        ->and($entry->after['secret'])->toBe(Redactor::PLACEHOLDER)
        ->and($entry->meta['db_password'])->toBe(Redactor::PLACEHOLDER)
        // Non-sensitive context survives, or the log would be useless.
        ->and($entry->before['merchant_id'])->toBe('M1');

    expect(json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR))
        ->not->toContain('old-secret')
        ->not->toContain('new-secret')
        ->not->toContain('hunter2');
});

it('keeps a denormalised actor label so history stays readable', function (): void {
    app(Audit::class)->record(new AuditEvent(
        action: 'platform.labelled',
        category: AuditCategory::System,
        actor: Actor::console('metastyle:tenant:provision'),
        targetType: 'Tenant',
        targetId: 'abc',
        targetLabel: 'Barbershop Alpha',
    ));

    $entry = PlatformAuditLog::query()->firstOrFail();

    // Resolving labels at read time would let renames rewrite history.
    expect($entry->actor_label)->toBe('metastyle:tenant:provision')
        ->and($entry->target_label)->toBe('Barbershop Alpha')
        ->and($entry->actor_type)->toBe(ActorType::Console->value);
});

it('refuses to update a platform audit entry', function (): void {
    app(Audit::class)->record(new AuditEvent(
        action: 'platform.immutable',
        category: AuditCategory::Finance,
        actor: Actor::system('test'),
    ));

    PlatformAuditLog::query()->firstOrFail()->update(['action' => 'tampered']);
})->throws(RuntimeException::class, 'append-only');

it('refuses to delete a platform audit entry', function (): void {
    app(Audit::class)->record(new AuditEvent(
        action: 'platform.immutable',
        category: AuditCategory::Finance,
        actor: Actor::system('test'),
    ));

    PlatformAuditLog::query()->firstOrFail()->delete();
})->throws(RuntimeException::class, 'append-only');

it('refuses to update a tenant audit entry', function (): void {
    $tenant = $this->provisionTenant('Alpha');

    $this->asTenant($tenant, function (): void {
        app(Audit::class)->record(new AuditEvent(
            action: 'tenant.immutable',
            category: AuditCategory::Finance,
            actor: Actor::system('test'),
        ));

        TenantAuditLog::query()->firstOrFail()->update(['action' => 'tampered']);
    });
})->throws(RuntimeException::class, 'append-only');

it('carries severity so critical history can be treated differently', function (): void {
    expect(AuditSeverity::Critical->requiresSynchronousWrite())->toBeTrue()
        ->and(AuditSeverity::Info->requiresSynchronousWrite())->toBeFalse()
        ->and(AuditCategory::Finance->isLongRetention())->toBeTrue()
        ->and(AuditCategory::Security->isLongRetention())->toBeTrue()
        ->and(AuditCategory::System->isLongRetention())->toBeFalse();
});

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
