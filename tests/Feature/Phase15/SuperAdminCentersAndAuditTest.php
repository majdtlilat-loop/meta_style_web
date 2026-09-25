<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Livewire\Sadmin\Audit\Index as AuditIndex;
use App\Livewire\Sadmin\Centers\Index as CentersIndex;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->platformUser = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->actingAs($this->platformUser, 'platform');
});

it('searches filters and sorts the center registry', function (): void {
    $alpha = centerRecord('Alpha Center', 'alpha-center', 'active', 'completed', now()->subDay());
    $zulu = centerRecord('Zulu Center', 'zulu-center', 'suspended', 'failed', now());

    Livewire::test(CentersIndex::class)
        ->assertSeeInOrder(['Zulu Center', 'Alpha Center'])
        ->call('sort', 'name')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Alpha Center', 'Zulu Center'])
        ->set('search', 'alpha')
        ->assertSee('Alpha Center')
        ->assertDontSee('Zulu Center')
        ->set('search', '')
        ->set('provisioningStatus', 'failed')
        ->assertSee('Zulu Center')
        ->assertDontSee('Alpha Center')
        ->set('provisioningStatus', '')
        ->set('status', 'active')
        ->assertSee('Alpha Center')
        ->assertDontSee('Zulu Center')
        ->set('perPage', 50)
        ->assertSet('perPage', 50)
        ->call('sort', 'not-a-column')
        ->assertSet('sortBy', 'name');

    expect($alpha->exists)->toBeTrue()
        ->and($zulu->exists)->toBeTrue();
});

it('filters platform audit history and opens readable event details', function (): void {
    $tenant = centerRecord('Audit Center', 'audit-center', 'active', 'completed', now());

    app(Audit::class)->recordForTenant($tenant->id, new AuditEvent(
        action: 'platform.plan.changed',
        category: AuditCategory::Config,
        actor: Actor::platform($this->platformUser),
        severity: AuditSeverity::Warning,
        targetType: 'subscription',
        targetId: 'sub-15',
        targetLabel: 'Business subscription',
        before: ['plan' => 'starter'],
        after: ['plan' => 'business'],
        meta: ['source' => 'center detail'],
        reason: 'Customer approved the commercial upgrade',
    ));

    app(Audit::class)->record(new AuditEvent(
        action: 'platform.settings.viewed',
        category: AuditCategory::System,
        actor: Actor::system(),
    ));

    $entry = PlatformAuditLog::query()->where('action', 'platform.plan.changed')->firstOrFail();

    Livewire::test(AuditIndex::class)
        ->assertSee('platform.plan.changed')
        ->assertSee('platform.settings.viewed')
        ->set('tenantId', $tenant->id)
        ->assertSee('platform.plan.changed')
        ->assertDontSee('platform.settings.viewed')
        ->set('actor', 'Meta Style Super Admin')
        ->assertSee('platform.plan.changed')
        ->set('target', 'Business subscription')
        ->assertSee('platform.plan.changed')
        ->set('severity', 'warning')
        ->assertSee('platform.plan.changed')
        ->call('showDetails', $entry->uuid)
        ->assertSet('selectedUuid', $entry->uuid)
        ->assertSee('Customer approved the commercial upgrade')
        ->assertSee('starter')
        ->assertSee('business')
        ->assertSee('center detail')
        ->call('closeDetails')
        ->assertSet('selectedUuid', null);
});

function centerRecord(string $name, string $slug, string $status, string $provisioning, mixed $createdAt): TenantModel
{
    return TenantModel::query()->create([
        'id' => (string) Str::uuid(),
        'public_key' => 'ctr_'.Str::lower(Str::random(32)),
        'slug' => $slug,
        'name' => $name,
        'status' => $status,
        'provisioning_status' => $provisioning,
        'migration_status' => $provisioning === 'completed' ? 'succeeded' : 'failed',
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}
