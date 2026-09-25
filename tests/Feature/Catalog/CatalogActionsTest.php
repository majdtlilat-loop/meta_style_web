<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TranslatedText;
use App\Livewire\Center\Catalog\ServiceEditor;
use App\Modules\Catalog\Application\Actions\ArchiveService;
use App\Modules\Catalog\Application\Actions\DuplicateService;
use App\Modules\Catalog\Application\Actions\SaveService;
use App\Modules\Catalog\Application\Actions\SaveServiceCategory;
use App\Modules\Catalog\Application\Actions\SetServiceStatus;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Departments\Application\Actions\SaveDepartment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Catalog actions behind the Manager services library
|--------------------------------------------------------------------------
|
| Duplicate, quick status changes, the editor that no longer resets what it
| does not show, and variations that are deactivated rather than deleted.
|
*/

it('duplicates a service as an inactive, hidden copy with its variations and links', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $catalog = $this->seedCatalog();
        $source = $catalog['service'];
        $branch = $this->seedBranch();
        $stylist = $this->seedEmployee('Sara');
        $next = Service::query()->create([
            'name' => TranslatedText::make('en', 'Blow dry'), 'service_category_id' => $catalog['category']->id,
            'duration_minutes' => 20, 'price_minor' => 8000, 'sort_order' => 1,
        ]);

        $source->forceFill(['available_at_all_branches' => false, 'description' => TranslatedText::make('en', 'Wash, cut and finish.')])->save();
        $source->branches()->sync([$branch->id]);
        $source->eligibleEmployees()->sync([$stylist->id]);
        $source->variations()->where('price_minor', 25000)->update(['is_active' => false]);

        $copy = app(DuplicateService::class)($source, $owner, ['en' => 'Haircut (copy)', 'ar' => 'قص شعر (نسخة)']);

        $variations = $copy->variations()->get();

        expect($copy->uuid)->not->toBe($source->uuid)
            ->and($copy->is_active)->toBeFalse()
            ->and($copy->is_public)->toBeFalse()
            ->and($copy->name->get('en'))->toBe('Haircut (copy)')
            ->and($copy->name->get('ar'))->toBe('قص شعر (نسخة)')
            ->and($copy->description?->get('en'))->toBe('Wash, cut and finish.')
            ->and($copy->price_minor)->toBe(20000)
            ->and($copy->duration_minutes)->toBe(30)
            ->and($copy->department_id)->toBe($source->department_id)
            ->and($copy->service_category_id)->toBe($source->service_category_id)
            // Only the active variations, as new rows; null keeps inheriting.
            ->and($variations)->toHaveCount(2)
            ->and($variations->pluck('uuid')->intersect($source->variations()->pluck('uuid'))->all())->toBe([])
            ->and($variations->firstWhere('price_minor', null)?->name->get('en'))->toBe('Standard')
            ->and($copy->addons()->pluck('service_addons.id')->all())->toBe([$catalog['addon']->id])
            ->and($copy->branches()->pluck('branches.id')->all())->toBe([$branch->id])
            ->and($copy->eligibleEmployees()->pluck('employees.id')->all())->toBe([$stylist->id])
            // Right after the original in its category.
            ->and(Service::query()->where('service_category_id', $catalog['category']->id)->orderBy('sort_order')->orderBy('id')->pluck('id')->all())
            ->toBe([$source->id, $copy->id, $next->id]);

        $entry = TenantAuditLog::query()->where('action', 'catalog.service.duplicated')->latest('id')->firstOrFail();

        expect($entry->target_id)->toBe($copy->uuid)
            ->and($entry->after['source'])->toBe($source->uuid)
            ->and($entry->after['is_public'])->toBeFalse();
    });
});

it('refuses to duplicate without service.create, or an archived service', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];
        $editor = $this->staffWith([Permission::ServiceView, Permission::ServiceUpdate]);

        expect(fn () => app(DuplicateService::class)($service, $editor))->toThrow(AuthorizationException::class);

        app(ArchiveService::class)($service, $owner);

        expect(fn () => app(DuplicateService::class)($service, $owner))->toThrow(ValidationException::class)
            ->and(Service::query()->count())->toBe(1);
    });
});

it('switches a service on and off without touching anything else', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];
        $service->forceFill(['description' => TranslatedText::make('en', 'Long text'), 'sort_order' => 4])->save();

        app(SetServiceStatus::class)($service, $owner, isActive: false, isOnlineBookable: false);
        $service->refresh();

        expect($service->is_active)->toBeFalse()
            ->and($service->is_online_bookable)->toBeFalse()
            ->and($service->is_public)->toBeTrue()
            ->and($service->description?->get('en'))->toBe('Long text')
            ->and($service->sort_order)->toBe(4)
            ->and($service->price_minor)->toBe(20000);

        $entry = TenantAuditLog::query()->where('action', 'catalog.service.status_changed')->latest('id')->firstOrFail();

        expect($entry->before)->toBe(['is_active' => true, 'is_online_bookable' => true])
            ->and($entry->after)->toBe(['is_active' => false, 'is_online_bookable' => false]);

        $viewer = $this->staffWith([Permission::ServiceView]);
        expect(fn () => app(SetServiceStatus::class)($service, $viewer, isActive: true))->toThrow(AuthorizationException::class);

        app(ArchiveService::class)($service, $owner);
        expect(fn () => app(SetServiceStatus::class)($service->refresh(), $owner, isActive: true))->toThrow(ValidationException::class);
    });
});

it('keeps the description, online booking and position when a service is edited in the Manager', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $catalog = $this->seedCatalog();
        $service = $catalog['service'];
        Service::query()->create([
            'name' => TranslatedText::make('en', 'Beard'), 'service_category_id' => $catalog['category']->id,
            'duration_minutes' => 15, 'price_minor' => 5000, 'sort_order' => 0,
        ]);
        $service->forceFill([
            'description' => TranslatedText::fromArray(['en' => 'A long description', 'ar' => 'وصف طويل']),
            'is_online_bookable' => false,
            'sort_order' => 1,
        ])->save();

        Livewire::actingAs($owner)
            ->test(ServiceEditor::class)
            ->call('edit', $service->uuid)
            ->assertSet('serviceDescription.en', 'A long description')
            ->assertSet('serviceOnline', false)
            ->set('price', '21000')
            ->call('saveService')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('catalog-changed');

        $service->refresh();

        // Before the redesign every web edit reset these three.
        expect($service->price_minor)->toBe(21000)
            ->and($service->description?->get('ar'))->toBe('وصف طويل')
            ->and($service->is_online_bookable)->toBeFalse()
            ->and($service->sort_order)->toBe(1)
            ->and($service->variations()->count())->toBe(3)
            ->and($service->addons()->count())->toBe(1);
    });
});

it('deactivates a variation a package refers to instead of failing to delete it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];
        $medium = $service->variations()->where('price_minor', 25000)->firstOrFail();

        // A package definition pins the variation with a RESTRICT foreign key.
        $definition = DB::connection('tenant')->table('package_definitions')->insertGetId([
            'uuid' => (string) Str::uuid(), 'name' => json_encode(['en' => 'Five cuts']),
            'price_minor' => 80000, 'validity_days' => 90, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('package_definition_items')->insert([
            'package_definition_id' => $definition, 'service_id' => $service->id,
            'service_variation_id' => $medium->id, 'quantity' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $editor = Livewire::actingAs($owner)->test(ServiceEditor::class)->call('edit', $service->uuid);
        $index = array_search($medium->uuid, array_column($editor->get('variations'), 'uuid'), true);

        // "Remove" on a saved variation switches it off; the save succeeds.
        $editor->call('removeVariation', $index)
            ->assertSet("variations.{$index}.active", false)
            ->call('saveService')
            ->assertHasNoErrors();

        expect(ServiceVariation::query()->whereKey($medium->id)->exists())->toBeTrue()
            ->and($medium->refresh()->is_active)->toBeFalse()
            ->and($service->variations()->where('is_active', true)->count())->toBe(2);

        // Leaving it out of an API-style save also deactivates, never deletes.
        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Haircut'], durationMinutes: 30, priceMinor: 20000, variations: [],
        ), $owner, $service->refresh());

        expect(ServiceVariation::query()->where('service_id', $service->id)->count())->toBe(3)
            ->and(ServiceVariation::query()->where('service_id', $service->id)->where('is_active', true)->count())->toBe(0);
    });
});

it('refuses an empty name, and keeps only languages the platform knows', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        expect(fn () => app(SaveService::class)(new ServiceInput(
            name: ['en' => '   '], durationMinutes: 30, priceMinor: 1000,
        ), $owner))->toThrow(ValidationException::class);

        $service = app(SaveService::class)(new ServiceInput(
            name: ['en' => ' Haircut ', 'xx' => 'Nonsense', 'ar' => 'قص شعر'], durationMinutes: 30, priceMinor: 1000,
        ), $owner);

        expect($service->name->all())->toBe(['en' => 'Haircut', 'ar' => 'قص شعر']);

        expect(fn () => app(SaveServiceCategory::class)(['en' => ''], $owner))->toThrow(ValidationException::class);
    });
});

it('refuses an archived category or department, and a variation outside one day', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $catalog = $this->seedCatalog();
        $retired = app(SaveServiceCategory::class)(['en' => 'Retired'], $owner);
        app(SaveServiceCategory::class)->archive($retired, $owner);

        $department = app(SaveDepartment::class)(['en' => 'Old'], $owner);
        app(SaveDepartment::class)->archive($department, $owner);

        expect(fn () => app(SaveService::class)(new ServiceInput(
            name: ['en' => 'X'], durationMinutes: 30, priceMinor: 1000, serviceCategoryId: $retired->id,
        ), $owner))->toThrow(ValidationException::class)
            ->and(fn () => app(SaveService::class)(new ServiceInput(
                name: ['en' => 'X'], durationMinutes: 30, priceMinor: 1000, departmentId: $department->id,
            ), $owner))->toThrow(ValidationException::class);

        try {
            app(SaveService::class)(new ServiceInput(
                name: ['en' => 'X'], durationMinutes: 30, priceMinor: 1000,
                variations: [['name' => ['en' => 'Marathon'], 'duration_minutes' => 2000]],
            ), $owner);
            $this->fail('A two-day variation was accepted.');
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['variations.0.duration_minutes']);
        }

        // A service already filed under a category that is later archived can
        // still be edited without re-filing it first.
        $kept = $catalog['service'];
        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'Haircut'], durationMinutes: 30, priceMinor: 20000, departmentId: $kept->department_id,
        ), $owner, $kept);

        expect(Service::query()->where('name->en', 'X')->exists())->toBeFalse();
    });
});

it('reports editor refusals next to the field, in the viewer language', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app()->setLocale('ar');

        Livewire::actingAs($owner)
            ->test(ServiceEditor::class)
            ->call('create')
            ->set('serviceName.en', 'Colour')
            ->set('price', '٢٥٠٠٠')
            ->set('duration', 45)
            ->set('allBranches', false)
            ->call('saveService')
            ->assertHasErrors(['branchUuids'])
            ->set('allBranches', true)
            ->call('addVariation')
            ->set('variations.0.name.en', 'Long')
            ->set('variations.0.price', '10.5')
            ->call('saveService')
            ->assertHasErrors(['variations.0.price']);

        expect(Service::query()->count())->toBe(0);

        // Arabic-Indic digits are a price like any other.
        Livewire::actingAs($owner)
            ->test(ServiceEditor::class)
            ->call('create')
            ->set('serviceName.en', 'Colour')
            ->set('price', '٢٥٬٠٠٠')
            ->set('duration', 45)
            ->call('saveService')
            ->assertHasNoErrors()
            // A new service stays open so its photos can be added.
            ->assertSet('open', true)
            ->assertNotSet('editingService', null);

        expect(Service::query()->firstOrFail()->price_minor)->toBe(25000);
    });
});

afterEach(function (): void {
    app()->setLocale('en');
    $this->tearDownRegisteredCenters();
});
