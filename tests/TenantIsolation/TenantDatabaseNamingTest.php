<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Tenancy\Actions\UpdateOwnCenterProfile;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\TenantDatabaseName;
use App\Modules\SaasAdmin\Application\Actions\UpdateCenterProfile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseManager;

/*
|--------------------------------------------------------------------------
| A new center's database name, end to end
|--------------------------------------------------------------------------
|
| ADR-106. A new center's database is `{prefix}{slug label}_{sequence}`, chosen
| ONCE at provisioning, stored on the control-plane tenant row, and resolved
| from there on every request. It is never re-derived: a rename or a new public
| address leaves it exactly where it is.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
    $this->tearDownTenantDatabases();
});

it('gives a new center a database named after its slug and its sequence', function (): void {
    $center = $this->registerCenter('Dr Bany', 'owner@drbany.test');
    $model = TenantModel::query()->findOrFail($center['tenant']->id);

    expect($model->slug)->toBe('dr-bany')
        ->and($model->tenancy_db_name)->toBe(TenantDatabaseName::generate($model->sequence, 'dr-bany'))
        ->and($model->tenancy_db_name)->toEndWith('dr_bany_'.str_pad((string) $model->sequence, 6, '0', STR_PAD_LEFT))
        // Created on the server under exactly the name the control plane holds.
        ->and(TestDatabaseManager::existing())->toContain($model->tenancy_db_name);
});

it('names a unicode center\'s database from its ASCII slug, never from its display name', function (): void {
    $center = $this->registerCenter('مركز التجميل', 'owner@beauty.test', slug: 'beauty-erbil');
    $model = TenantModel::query()->findOrFail($center['tenant']->id);

    expect($model->name)->toBe('مركز التجميل')
        ->and($model->tenancy_db_name)->toBe(TenantDatabaseName::generate($model->sequence, 'beauty-erbil'))
        ->and(preg_match('/^[a-z0-9_]+$/', (string) $model->tenancy_db_name))->toBe(1);
});

/**
 * Drops whatever database the console made for $name, whether or not the
 * command succeeded: the name is read from the control plane, not assumed.
 */
function namingDropConsoleTenant(string $name): void
{
    foreach (TenantModel::query()->where('name', $name)->pluck('tenancy_db_name') as $database) {
        if (is_string($database) && $database !== '') {
            TestDatabaseManager::drop($database);
        }
    }
}

it('provisions from the console with the slug it is given as the label', function (): void {
    try {
        expect(Artisan::call('metastyle:tenant:provision', ['name' => 'Salon Élite', '--slug' => 'salon-elite']))->toBe(0);

        $model = TenantModel::query()->where('name', 'Salon Élite')->sole();

        expect($model->tenancy_db_name)->toBe(TenantDatabaseName::generate($model->sequence, 'salon-elite'));
    } finally {
        namingDropConsoleTenant('Salon Élite');
    }
});

it('never labels a console center\'s database with its display name', function (): void {
    try {
        expect(Artisan::call('metastyle:tenant:provision', ['name' => 'مركز التجميل']))->toBe(0);

        $model = TenantModel::query()->where('name', 'مركز التجميل')->sole();

        // No slug, no label from the name — not even a transliteration of it.
        expect($model->tenancy_db_name)->toBe(TenantDatabaseName::generate($model->sequence, null));
    } finally {
        namingDropConsoleTenant('مركز التجميل');
    }
});

it('refuses a console slug the platform would not accept, and creates nothing', function (): void {
    try {
        expect(Artisan::call('metastyle:tenant:provision', ['name' => 'Bad Slug Salon', '--slug' => "salon'; DROP DATABASE x; --"]))->toBe(1)
            ->and(TenantModel::query()->where('name', 'Bad Slug Salon')->exists())->toBeFalse();
    } finally {
        namingDropConsoleTenant('Bad Slug Salon');
    }
});

it('never lets a hostile center name reach the database name', function (): void {
    $tenant = $this->provisionTenant("Salon'; DROP DATABASE meta_style_control; --");

    expect($tenant->databaseName)->toBe(TenantDatabaseName::generate($tenant->sequence, null))
        ->and(TenantDatabaseName::isValid($tenant->databaseName))->toBeTrue()
        ->and(preg_match('/^[a-z0-9_]+$/', $tenant->databaseName))->toBe(1)
        ->and(str_contains($tenant->databaseName, 'salon'))->toBeFalse();
});

it('never renames the database when the center is renamed or moves to a new address', function (): void {
    $center = $this->registerCenter('Dr Bany', 'owner@drbany.test');
    $model = TenantModel::query()->findOrFail($center['tenant']->id);
    $database = (string) $model->tenancy_db_name;
    $owner = $this->ownerOf($center['tenant']);

    // The owner renames the center; the platform moves it to a new address.
    $this->asCenter($center['tenant'], fn () => app(UpdateOwnCenterProfile::class)($owner, ['name' => 'Bany Clinic']));
    app(UpdateCenterProfile::class)->changeAddress($model->fresh() ?? $model, 'bany-clinic', Actor::system('naming-test'), 'New public address');

    $model = $model->fresh() ?? $model;

    expect($model->name)->toBe('Bany Clinic')
        ->and($model->slug)->toBe('bany-clinic')
        ->and($model->tenancy_db_name)->toBe($database);

    // Resolution still lands in that same database: through the tenant
    // context, and through a real request on the new address.
    $bound = $this->asCenter($model->toValueObject(), fn (): string => (string) DB::connection('tenant')->getDatabaseName());

    expect($bound)->toBe($database);

    $this->get('http://bany-clinic.localhost:8000/')->assertOk();
});

it('keeps centers whose slugs reduce to the same label apart, with their own data', function (): void {
    // Both slugs are longer than the label: cut to the same 24 characters,
    // only the sequence suffix can tell them apart.
    $north = $this->registerCenter('Beauty Center Of The Old City North', 'owner@north.test');
    $south = $this->registerCenter('Beauty Center Of The Old City South', 'owner@south.test');

    $northDb = (string) TenantModel::query()->whereKey($north['tenant']->id)->value('tenancy_db_name');
    $southDb = (string) TenantModel::query()->whereKey($south['tenant']->id)->value('tenancy_db_name');

    $label = static fn (string $name): string => (string) preg_replace('/_[0-9]+$/', '', $name);

    expect($label($northDb))->toBe($label($southDb))
        ->and($northDb)->not->toBe($southDb);

    // And they are two databases, not two names for one.
    $this->asCenter($north['tenant'], fn () => DB::connection('tenant')->table('settings')->insert(['key' => 'naming_probe', 'value' => json_encode('north')]));

    $seen = $this->asCenter($south['tenant'], fn () => DB::connection('tenant')->table('settings')->where('key', 'naming_probe')->exists());

    expect($seen)->toBeFalse()
        ->and($this->asCenter($north['tenant'], fn (): string => (string) DB::connection('tenant')->getDatabaseName()))->toBe($northDb)
        ->and($this->asCenter($south['tenant'], fn (): string => (string) DB::connection('tenant')->getDatabaseName()))->toBe($southDb);
});

it('builds the operational tables in the center database and never in the control plane', function (): void {
    $center = $this->registerCenter('Dr Bany', 'owner@drbany.test');

    $inTenant = $this->asCenter($center['tenant'], fn (): bool => Schema::connection('tenant')->hasTable('appointments')
        && Schema::connection('tenant')->hasTable('customers'));

    expect($inTenant)->toBeTrue()
        ->and(Schema::connection('control')->hasTable('appointments'))->toBeFalse()
        ->and(Schema::connection('control')->hasTable('customers'))->toBeFalse();
});
