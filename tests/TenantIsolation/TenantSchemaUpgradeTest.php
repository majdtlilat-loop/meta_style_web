<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Tenancy\TenantMigrator;
use App\Livewire\Center\Queue\DisplayMedia;
use App\Livewire\Center\Queue\Displays;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Every center reaches the SAME, latest tenant schema
|--------------------------------------------------------------------------
|
| A center provisioned today and a center provisioned before a migration
| existed must end up with the same schema through the same mechanism — the
| tenant migration path — never a hand-written CREATE TABLE for one center.
|
| The regression this pins: the center site builder added `site_versions`, new
| centers received it at provisioning, and existing centers only receive it
| when `metastyle:tenant:migrate` runs (TenantMigrator). The upgrade must keep
| the center's data, never duplicate a table or a migration row, never touch
| the control database, and leave the new feature working.
|
*/

/** @return list<string> Migration names in the authoritative tenant path. */
function tenantMigrationNames(): array
{
    return collect(glob(database_path('migrations/tenant/*.php')) ?: [])
        ->map(static fn (string $path): string => basename($path, '.php'))
        ->sort()
        ->values()
        ->all();
}

const CENTER_SITE_MIGRATION = '2026_09_24_1801_create_site_versions_table';

/*
 * Queue-screen promotional media and language rotation, and the guest WhatsApp
 * booking notices — the four tenant migrations after the center site one.
 */
const SCREEN_AND_NOTICE_MIGRATIONS = [
    '2026_09_24_2301_add_promo_settings_to_queue_displays_table',
    '2026_09_24_2302_add_language_rotation_to_queue_displays_table',
    '2026_09_24_2303_create_queue_display_media_table',
    '2026_09_24_2901_create_whatsapp_outbound_notices_table',
];

it('provisions a new center at the latest tenant migration, center site tables included', function (): void {
    $center = $this->provisionTenant('Fresh Center');

    $this->asTenant($center, function (): void {
        $applied = DB::connection('tenant')->table('migrations')->orderBy('migration')->pluck('migration')->all();

        expect($applied)->toBe(tenantMigrationNames())
            ->and(Schema::connection('tenant')->hasTable('site_versions'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasTable('settings'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasTable('media_items'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasTable('queue_display_media'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasColumn('queue_displays', 'rotation_enabled'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasTable('whatsapp_outbound_notices'))->toBeTrue();
    });

    expect($this->tenantModel($center)->schema_version)->toBe(TenantMigrator::targetSchemaVersion());
    // The center's site lives in the center's database, never the control plane.
    expect(Schema::connection('control')->hasTable('site_versions'))->toBeFalse()
        ->and(Schema::connection('control')->hasTable('queue_display_media'))->toBeFalse()
        ->and(Schema::connection('control')->hasTable('whatsapp_outbound_notices'))->toBeFalse();
});

it('upgrades a center provisioned before the center site migration, keeping its data', function (): void {
    $center = $this->provisionTenant('Older Center');

    // Put the center back where it was before the center site migration existed.
    $this->asTenant($center, function (): void {
        Schema::connection('tenant')->drop('site_versions');
        DB::connection('tenant')->table('migrations')->where('migration', CENTER_SITE_MIGRATION)->delete();
        DB::connection('tenant')->table('settings')->insert(['key' => 'upgrade.probe', 'value' => json_encode('kept')]);

        expect(Schema::connection('tenant')->hasTable('site_versions'))->toBeFalse();
    });
    $model = $this->tenantModel($center);
    $model->forceFill(['schema_version' => '2026_09_24_1601_add_branch_requested_index_to_refunds_table'])->save();

    $settingsBefore = $this->asTenant($center, fn (): int => DB::connection('tenant')->table('settings')->count());

    // The normal lifecycle: the same migrator `metastyle:tenant:migrate` runs.
    $results = app(TenantMigrator::class)->migrateMany([$this->tenantModel($center)], Actor::system('test'));
    expect($results)->toHaveCount(1)->and($results[0]->isSucceeded())->toBeTrue();

    $this->asTenant($center, function () use ($settingsBefore): void {
        expect(Schema::connection('tenant')->hasTable('site_versions'))->toBeTrue()
            ->and(DB::connection('tenant')->table('settings')->count())->toBe($settingsBefore)
            ->and(json_decode((string) DB::connection('tenant')->table('settings')->where('key', 'upgrade.probe')->value('value')))->toBe('kept')
            ->and(DB::connection('tenant')->table('migrations')->where('migration', CENTER_SITE_MIGRATION)->count())->toBe(1);
    });

    // Running it again is a no-op: no duplicate table, no second migration row, no failure.
    $again = app(TenantMigrator::class)->migrateMany([$this->tenantModel($center)], Actor::system('test'));
    expect($again[0]->isSucceeded())->toBeTrue();
    $this->asTenant($center, function (): void {
        expect(DB::connection('tenant')->table('migrations')->where('migration', CENTER_SITE_MIGRATION)->count())->toBe(1)
            ->and(DB::connection('tenant')->table('migrations')->pluck('migration')->sort()->values()->all())->toBe(tenantMigrationNames());

        // The feature works on the upgraded center.
        expect(app(SitePublisher::class)->editableContent())->toBeArray()->not->toBeEmpty();
    });

    expect($this->tenantModel($center)->schema_version)->toBe(TenantMigrator::targetSchemaVersion())
        ->and(Schema::connection('control')->hasTable('site_versions'))->toBeFalse();
});

it('upgrades a center with a live queue to the screen-media and guest-notice schema, keeping its queue', function (): void {
    $center = $this->registerCenter('Queue Upgrade Center', 'owner@queue-upgrade.test');
    $slug = (string) $center['registration']->requested_slug;

    // A center that already runs a queue: a screen and a waiting ticket.
    $refs = $this->asCenter($center['tenant'], function (): array {
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        $display = $this->seedDisplay($seed['branch'], 'Hall TV', locale: 'ar');
        $ticket = app(CreateWalkInTicket::class)(
            new WalkInRequest(branchUuid: $seed['branch']->uuid, serviceUuids: [$seed['service']->uuid], name: 'Upgrade Guest'),
            $this->ownerWithCatalogAccess(),
            [],
        )['ticket'];

        return ['display' => $display->uuid, 'ticket' => $ticket->uuid];
    });

    // Back to the schema this center had before the four migrations existed.
    $this->asCenter($center['tenant'], function (): void {
        $schema = Schema::connection('tenant');
        $schema->dropIfExists('whatsapp_outbound_notices');
        $schema->dropIfExists('queue_display_media');
        $schema->table('queue_displays', static function (Blueprint $table): void {
            $table->dropColumn(['rotation_enabled', 'rotation_locales', 'rotation_seconds', 'promo_enabled', 'promo_slide_seconds']);
        });
        DB::connection('tenant')->table('migrations')->whereIn('migration', SCREEN_AND_NOTICE_MIGRATIONS)->delete();

        expect($schema->hasTable('queue_display_media'))->toBeFalse()
            ->and($schema->hasColumn('queue_displays', 'rotation_enabled'))->toBeFalse();
    });
    $this->tenantModel($center['tenant'])->forceFill(['schema_version' => CENTER_SITE_MIGRATION])->save();

    // The normal lifecycle: the migrator `metastyle:tenant:migrate` runs.
    $results = app(TenantMigrator::class)->migrateMany([$this->tenantModel($center['tenant'])], Actor::system('test'));
    expect($results)->toHaveCount(1)->and($results[0]->isSucceeded())->toBeTrue();

    $this->asCenter($center['tenant'], function () use ($refs, $slug): void {
        $schema = Schema::connection('tenant');
        expect($schema->hasTable('queue_display_media'))->toBeTrue()
            ->and($schema->hasTable('whatsapp_outbound_notices'))->toBeTrue()
            ->and(DB::connection('tenant')->table('migrations')->whereIn('migration', SCREEN_AND_NOTICE_MIGRATIONS)->count())->toBe(4);

        // The queue is intact, and the older screen reads today's behaviour
        // from the new columns' defaults: no media, no rotation, 10 seconds.
        $display = QueueDisplay::query()->where('uuid', $refs['display'])->sole();
        expect($display->name)->toBe('Hall TV')
            ->and($display->locale)->toBe('ar')
            ->and($display->voiceLocales())->toBe(['en', 'ar', 'ckb'])
            ->and($display->rotation_enabled)->toBeFalse()
            ->and($display->rotationSeconds())->toBe(10)
            ->and($display->promo_enabled)->toBeFalse()
            ->and(QueueTicket::query()->where('uuid', $refs['ticket'])->sole()->state)->toBe(TicketState::Waiting);

        // The screens list — whose media counts failed before the upgrade —
        // and the promotional media panel both work on the upgraded center.
        URL::defaults(['center' => $slug]);
        $owner = $this->ownerWithCatalogAccess();

        Livewire::actingAs($owner)->test(Displays::class)
            ->assertOk()
            ->assertSee('Hall TV')
            ->call('openMedia', $refs['display'])
            ->assertSet('mediaFor', $refs['display']);

        Livewire::actingAs($owner)->test(DisplayMedia::class, ['display' => $refs['display']])->assertOk();
    });

    // Running it again is a no-op: no duplicate table or migration row.
    $again = app(TenantMigrator::class)->migrateMany([$this->tenantModel($center['tenant'])], Actor::system('test'));
    expect($again[0]->isSucceeded())->toBeTrue();

    $this->asCenter($center['tenant'], function (): void {
        expect(DB::connection('tenant')->table('migrations')->whereIn('migration', SCREEN_AND_NOTICE_MIGRATIONS)->count())->toBe(4)
            ->and(DB::connection('tenant')->table('migrations')->pluck('migration')->sort()->values()->all())->toBe(tenantMigrationNames());
    });

    expect($this->tenantModel($center['tenant'])->schema_version)->toBe(TenantMigrator::targetSchemaVersion())
        ->and(Schema::connection('control')->hasTable('queue_display_media'))->toBeFalse()
        ->and(Schema::connection('control')->hasTable('whatsapp_outbound_notices'))->toBeFalse();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
