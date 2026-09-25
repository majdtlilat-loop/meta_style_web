<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\ArchiveService;
use App\Modules\Catalog\Application\Actions\SaveService;
use App\Modules\Catalog\Application\Actions\SaveServiceCategory;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Departments\Application\Actions\SaveDepartment;
use App\Modules\Departments\Domain\Models\Department;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Service catalog
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 4 §§4–9 · docs/DECISIONS.md ADR-037.
|
| The two things most worth pinning down here are money and inheritance:
| prices are integers in minor units with IQD at exponent 0, and a variation
| with a null price FOLLOWS its service rather than freezing a copy.
|
*/

it('stores prices as integers in minor units, with IQD at zero decimals', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();

        $raw = DB::connection('tenant')->table('services')
            ->where('id', $catalog['service']->id)->value('price_minor');

        // The column holds an integer, not a decimal and certainly not a float.
        expect($raw)->toBe(20000);

        $price = $catalog['service']->price(Currency::IQD);

        expect($price->minor)->toBe(20000)
            ->and($price->formatted('en'))->toBe('20,000 IQD')
            // Twenty thousand dinars. Not two hundred.
            ->and($price->toMajorString())->toBe('20000');
    });
});

it('keeps translations for every enabled locale', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();

        expect($catalog['service']->name->get('en'))->toBe('Haircut')
            ->and($catalog['service']->name->get('ar'))->toBe('قص شعر')
            ->and($catalog['department']->name->get('ar'))->toBe('الشعر');

        // Stored as JSON, not as name_ar / name_en columns.
        $raw = (string) DB::connection('tenant')->table('services')
            ->where('id', $catalog['service']->id)->value('name');

        expect(json_decode($raw, true))->toBe(['en' => 'Haircut', 'ar' => 'قص شعر']);
    });
});

it('makes a variation with no price of its own follow the service', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();
        $service = $catalog['service'];

        $inherits = $service->variations()->where('price_minor', null)->firstOrFail();
        $overrides = $service->variations()->where('price_minor', 25000)->firstOrFail();

        expect($inherits->effectivePrice($service, Currency::IQD)->minor)->toBe(20000)
            ->and($inherits->effectiveDurationMinutes($service))->toBe(30)
            ->and($overrides->effectivePrice($service, Currency::IQD)->minor)->toBe(25000);

        // Raise the base price. The inheriting variation must follow; the
        // overriding one must not.
        $service->forceFill(['price_minor' => 30000])->save();
        $service->refresh();

        expect($inherits->effectivePrice($service, Currency::IQD)->minor)->toBe(30000)
            ->and($overrides->effectivePrice($service, Currency::IQD)->minor)->toBe(25000)
            ->and($inherits->overridesPrice())->toBeFalse()
            ->and($overrides->overridesPrice())->toBeTrue();
    });
});

it('shares one add-on across several services', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();
        $addon = $catalog['addon'];

        /** @var Service $second */
        $second = Service::query()->create([
            'name' => TranslatedText::make('en', 'Beard Trim'),
            'duration_minutes' => 15,
            'price_minor' => 10000,
        ]);

        $second->addons()->attach($addon->id);

        // One row edited once, reflected in both services — the reason add-ons
        // are shared rather than owned.
        $addon->forceFill(['price_minor' => 7000])->save();

        expect($catalog['service']->addons()->first()->price_minor)->toBe(7000)
            ->and($second->addons()->first()->price_minor)->toBe(7000)
            ->and($addon->services()->count())->toBe(2);
    });
});

it('treats "all branches" as the absence of rows', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $main = Branch::main();
        $second = $this->seedBranch();

        $everywhere = app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Everywhere'],
            durationMinutes: 30,
            priceMinor: 10000,
            availableAtAllBranches: true,
            branchIds: [],
        ), $owner);

        $restricted = app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Main only'],
            durationMinutes: 30,
            priceMinor: 10000,
            availableAtAllBranches: false,
            branchIds: [$main->id],
        ), $owner);

        // Adding a branch must not mean touching every service.
        expect($everywhere->branches()->count())->toBe(0)
            ->and($everywhere->isAvailableAtBranch($second->id))->toBeTrue()
            ->and($restricted->branches()->count())->toBe(1)
            ->and($restricted->isAvailableAtBranch($main->id))->toBeTrue()
            ->and($restricted->isAvailableAtBranch($second->id))->toBeFalse();

        // And the same answer from the query, not from PHP — a menu must not
        // load every service to filter one branch.
        $atSecond = Service::query()->atBranch($second->id)->pluck('id')->all();

        expect($atSecond)->toContain($everywhere->id)
            ->and($atSecond)->not->toContain($restricted->id);
    });
});

it('refuses a service restricted to no branches at all', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // Available nowhere is never what anyone meant.
        expect(fn () => app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Nowhere'],
            durationMinutes: 30,
            priceMinor: 1000,
            availableAtAllBranches: false,
            branchIds: [],
        ), $owner))->toThrow(ValidationException::class);
    });
});

it('records which employees may perform a service, and nothing more', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $stylist = $this->seedEmployee('Sara');
        $other = $this->seedEmployee('Ali');

        $service = app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Colour'],
            durationMinutes: 90,
            priceMinor: 45000,
            employeeIds: [$stylist->id],
        ), $owner);

        // Qualified: `id` alone is ambiguous across the join.
        $eligible = $service->eligibleEmployees()->pluck('employees.id')->all();

        expect($eligible)->toBe([$stylist->id])
            ->and($eligible)->not->toContain($other->id);

        // Eligibility only. No schedule, no availability, no commission —
        // Booking will combine this with those when it exists.
        $columns = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('employee_service');

        sort($columns);

        expect($columns)->toBe(['employee_id', 'id', 'service_id']);
    });
});

it('replaces variations by uuid instead of recreating them', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Haircut'],
            durationMinutes: 30,
            priceMinor: 20000,
            variations: [
                ['name' => ['en' => 'Short'], 'price_minor' => 20000],
                ['name' => ['en' => 'Long'], 'price_minor' => 30000],
            ],
        ), $owner);

        $short = $service->variations()->firstOrFail();
        $originalId = $short->id;
        $long = $service->variations()->where('uuid', '!=', $short->uuid)->firstOrFail();

        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Haircut'],
            durationMinutes: 30,
            priceMinor: 20000,
            variations: [
                ['uuid' => $short->uuid, 'name' => ['en' => 'Short Hair'], 'price_minor' => 22000],
            ],
        ), $owner, $service);

        $service->refresh();

        // The kept variation keeps its id, because bookings and invoice lines
        // reference it. The dropped one is DEACTIVATED, not deleted: package
        // definitions point at variations with a restricting foreign key, and
        // history keeps its link (Manager services library, Phase 15).
        $active = $service->variations()->where('is_active', true)->get();

        expect($active)->toHaveCount(1)
            ->and($active->firstOrFail()->id)->toBe($originalId)
            ->and($active->firstOrFail()->price_minor)->toBe(22000)
            ->and($service->variations()->count())->toBe(2)
            ->and($long->refresh()->is_active)->toBeFalse();
    });
});

it('leaves relations alone when the caller does not mention them', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $stylist = $this->seedEmployee('Sara');

        $service = app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Colour'],
            durationMinutes: 90,
            priceMinor: 45000,
            employeeIds: [$stylist->id],
        ), $owner);

        // A partial update — a price change from a different screen — must not
        // silently wipe eligibility. Null means "leave it", [] means "clear it".
        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Colour'],
            durationMinutes: 90,
            priceMinor: 50000,
            employeeIds: null,
        ), $owner, $service);

        expect($service->refresh()->eligibleEmployees()->count())->toBe(1);

        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Colour'],
            durationMinutes: 90,
            priceMinor: 50000,
            employeeIds: [],
        ), $owner, $service);

        expect($service->refresh()->eligibleEmployees()->count())->toBe(0);
    });
});

it('archives a service rather than deleting it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];

        app(ArchiveService::class)($service, $owner);

        $service->refresh();

        // The row survives: invoices from Phase 9 will point at it.
        expect(Service::query()->whereKey($service->id)->exists())->toBeTrue()
            ->and($service->archived_at)->not->toBeNull()
            ->and($service->is_public)->toBeFalse()
            ->and($service->is_online_bookable)->toBeFalse()
            ->and(Service::query()->publiclyVisible()->whereKey($service->id)->exists())->toBeFalse();

        // Restored inactive and non-public, so a stale price is checked before
        // customers can buy at it.
        app(ArchiveService::class)->restore($service, $owner);

        expect($service->refresh()->archived_at)->toBeNull()
            ->and($service->is_active)->toBeFalse()
            ->and($service->is_public)->toBeFalse();
    });
});

it('refuses to archive a department that still has active services', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $catalog = $this->seedCatalog();

        // A service with no department has lost the operational routing Queue
        // and the Service Journey will need.
        expect(fn () => app(SaveDepartment::class)->archive($catalog['department'], $owner))
            ->toThrow(ValidationException::class, 'still has 1 active service');
    });
});

it('lets a category be archived, leaving its services uncategorised', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $catalog = $this->seedCatalog();

        app(SaveServiceCategory::class)
            ->archive($catalog['category'], $owner);

        // Deliberately asymmetric with departments: a service with no menu
        // category is still perfectly sellable, it is just ungrouped.
        expect($catalog['service']->refresh()->service_category_id)->toBeNull()
            ->and($catalog['service']->is_active)->toBeTrue();
    });
});

it('keeps department and category as genuinely separate concepts', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();

        // A service carries both, independently. A center may put services
        // from three departments into one menu category.
        expect($catalog['service']->department_id)->toBe($catalog['department']->id)
            ->and($catalog['service']->service_category_id)->toBe($catalog['category']->id);

        $departmentTable = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('departments');
        $categoryTable = DB::connection('tenant')->getSchemaBuilder()->getColumnListing('service_categories');

        // Two tables, and only the customer-facing one has public visibility.
        expect($categoryTable)->toContain('is_public')
            ->and($departmentTable)->not->toContain('is_public');
    });
});

it('refuses catalog changes without the right permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        /** @var User $cashier */
        $cashier = User::query()->create([
            'name' => 'Till',
            'email' => 'cashier@alpha.test',
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => true,
        ]);

        $role = Role::query()->where('key', SystemRole::Cashier->value)->firstOrFail();
        $cashier->roles()->sync([$role->id]);
        $cashier->forgetPermissionCache();

        // A cashier reads prices at the till and changes none of them.
        expect($cashier->hasPermission(Permission::ServiceView))->toBeTrue()
            ->and($cashier->hasPermission(Permission::ServiceCreate))->toBeFalse();

        expect(fn () => app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Sneaky'],
            durationMinutes: 30,
            priceMinor: 1,
        ), $cashier))->toThrow(AuthorizationException::class);
    });
});

it('separates updating a service from retiring one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $service = $this->seedCatalog()['service'];

        /** @var User $senior */
        $senior = User::query()->create([
            'name' => 'Senior Stylist',
            'email' => 'senior@alpha.test',
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => true,
        ]);

        /** @var Role $role */
        $role = Role::query()->create([
            'key' => 'senior-stylist',
            'name' => TranslatedText::make('en', 'Senior stylist'),
            'is_system' => false,
        ]);

        // May adjust prices; may not retire what the business sells.
        $role->syncPermissions([Permission::ServiceView, Permission::ServiceUpdate]);

        $senior->roles()->sync([$role->id]);
        $senior->forgetPermissionCache();

        $updated = app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Haircut'],
            durationMinutes: 30,
            priceMinor: 21000,
        ), $senior, $service);

        expect($updated->price_minor)->toBe(21000);

        expect(fn () => app(ArchiveService::class)($service, $senior))
            ->toThrow(AuthorizationException::class);
    });
});

it('audits a price change with what it was and what it became', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $entry = $this->asCenter($center['tenant'], function () use ($owner) {
        $service = $this->seedCatalog()['service'];

        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Haircut'],
            durationMinutes: 45,
            priceMinor: 27500,
        ), $owner, $service);

        return TenantAuditLog::query()
            ->where('action', 'catalog.service.updated')
            ->latest('id')
            ->firstOrFail();
    });

    // "The price on the invoice was wrong" is only answerable if the trail says
    // what it was and when it changed.
    expect($entry->before['price_minor'])->toBe(20000)
        ->and($entry->after['price_minor'])->toBe(27500)
        ->and($entry->before['duration_minutes'])->toBe(30)
        ->and($entry->after['duration_minutes'])->toBe(45);
});

it('manages the catalog through the API', function (): void {
    $center = $this->registerCenter();
    $token = $this->apiTokenFor($center['tenant']);
    $headers = $this->tokenHeaders($token);

    $department = $this->withHeaders($headers)->postJson('/api/v1/tenant/catalog/departments', [
        'name' => ['en' => 'Laser', 'ar' => 'ليزر'],
    ])->assertStatus(201)->json('data.uuid');

    $category = $this->withHeaders($headers)->postJson('/api/v1/tenant/catalog/categories', [
        'name' => ['en' => 'Laser Treatments'],
    ])->assertStatus(201)->json('data.uuid');

    $service = $this->withHeaders($headers)->postJson('/api/v1/tenant/catalog/services', [
        'name' => ['en' => 'Full Legs', 'ar' => 'الساقين'],
        'department' => $department,
        'category' => $category,
        'duration_minutes' => 45,
        'price_minor' => 60000,
        'variations' => [
            ['name' => ['en' => 'Single session'], 'price_minor' => 60000],
            ['name' => ['en' => 'Course of six'], 'price_minor' => 300000],
        ],
    ])->assertStatus(201)->json('data');

    expect($service['price']['amount'])->toBe(60000)
        ->and($service['price']['currency'])->toBe('IQD')
        ->and($service['price']['formatted'])->toBe('60,000 IQD')
        ->and($service['variations'])->toHaveCount(2)
        ->and($service['department'])->toBe($department);

    $this->withHeaders($headers)->getJson('/api/v1/tenant/catalog')
        ->assertOk()
        ->assertJsonPath('data.currency', 'IQD')
        ->assertJsonCount(1, 'data.services');

    $this->withHeaders($headers)
        ->deleteJson("/api/v1/tenant/catalog/services/{$service['uuid']}")
        ->assertOk();

    $this->withHeaders($headers)->getJson('/api/v1/tenant/catalog')
        ->assertOk()
        ->assertJsonCount(0, 'data.services');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
