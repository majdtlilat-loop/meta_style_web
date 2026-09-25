<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaStore;
use App\Livewire\Center\Catalog;
use App\Livewire\Center\Catalog\CategoryEditor;
use App\Livewire\Center\Catalog\MediaGallery;
use App\Livewire\Center\Catalog\QuickAdd;
use App\Livewire\Center\Catalog\ServiceEditor;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager › Services — the service library page
|--------------------------------------------------------------------------
|
| The page, its drawers, its gallery and its quick add, driven the way a
| browser drives them. Every write lands in a Catalog Action; these check the
| wiring, the permission gate, the languages and that an order survives a
| reload.
|
*/

/**
 * Hair (Haircut, Beard trim, Shave) · Nails (Manicure) · uncategorised (Consultation).
 *
 * @return array{hair: ServiceCategory, nails: ServiceCategory, s: array<string, Service>}
 */
function libraryFixture(): array
{
    $hair = ServiceCategory::query()->create(['name' => TranslatedText::fromArray(['en' => 'Hair', 'ar' => 'الشعر']), 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
    $nails = ServiceCategory::query()->create(['name' => TranslatedText::make('en', 'Nails'), 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);

    $s = [];
    $rows = [
        'Haircut' => [$hair, ['ar' => 'قص شعر']],
        'Beard trim' => [$hair, []],
        'Shave' => [$hair, []],
        'Manicure' => [$nails, []],
        'Consultation' => [null, []],
    ];
    $position = 0;
    foreach ($rows as $name => [$category, $more]) {
        $s[$name] = Service::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name, ...$more]),
            'service_category_id' => $category?->id,
            'duration_minutes' => 30,
            'price_minor' => 15000,
            'sort_order' => $position++,
        ]);
    }

    return ['hair' => $hair, 'nails' => $nails, 's' => $s];
}

/**
 * @return list<string>
 */
function libraryOrder(ServiceCategory $category): array
{
    return Service::query()->where('service_category_id', $category->id)->whereNull('archived_at')
        ->orderBy('sort_order')->orderBy('id')->get()->map(fn (Service $s): string => $s->name->get('en'))->all();
}

it('renders the library in every interface language, names in the viewer language first', function (string $locale, string $direction, string $title, string $haircut): void {
    $center = $this->registerCenter('Library Center', 'owner@library.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale, $direction, $title, $haircut): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        libraryFixture();
        $this->actingAs($owner);

        $html = $this->get("http://{$slug}.localhost:8000/manager/catalog?locale={$locale}")
            ->assertOk()
            ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
            ->assertSee('<h1>'.$title.'</h1>', false)
            ->assertSee($haircut)
            // Only English exists: the center's primary language is used.
            ->assertSee('Beard trim')
            ->assertSee('data-sortable-handle', false)
            ->assertSee('x-sortable="moveService"', false)
            ->getContent();

        expect(preg_match('/\bmanager_catalog\.[a-z_]+/', strip_tags((string) $html)))->toBe(0)
            ->and(str_contains((string) $html, 'CKB'))->toBeFalse();
    });
})->with([
    'English' => ['en', 'ltr', 'Services', 'Haircut'],
    'Arabic' => ['ar', 'rtl', 'الخدمات', 'قص شعر'],
    'Kurdish Sorani' => ['ckb', 'rtl', 'خزمەتگوزارییەکان', 'Haircut'],
]);

it('refuses the library to staff without service.view', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        $this->actingAs($this->staffWith([Permission::BranchView]));

        $this->get("http://{$slug}.localhost:8000/manager/catalog")->assertForbidden();
    });
});

it('lets a read-only viewer browse without any ordering or editing controls', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        libraryFixture();

        Livewire::actingAs($this->staffWith([Permission::ServiceView]))
            ->test(Catalog::class)
            ->assertOk()
            ->assertSee('Haircut')
            ->assertDontSee('data-sortable-handle', false)
            ->assertDontSee('duplicateService', false)
            ->assertDontSee('moveServiceBy', false)
            ->assertDontSee(__('manager_catalog.actions.add_service'))
            ->assertDontSee(__('manager_catalog.actions.add_category'));
    });
});

it('orders by drag and by the Move buttons, and the order survives a reload', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['hair' => $hair, 'nails' => $nails, 's' => $s] = libraryFixture();

        Livewire::actingAs($owner)
            ->test(Catalog::class)
            ->call('moveService', $s['Shave']->uuid, 0)
            ->call('moveServiceBy', $s['Haircut']->uuid, 1)
            ->assertSet('notice', '');

        expect(libraryOrder($hair))->toBe(['Shave', 'Beard trim', 'Haircut']);

        // A fresh component — a reload — renders the stored order.
        Livewire::actingAs($owner)
            ->test(Catalog::class, ['category' => $hair->uuid])
            ->assertSeeInOrder(['Shave', 'Beard trim', 'Haircut']);

        Livewire::actingAs($owner)
            ->test(Catalog::class)
            ->call('moveCategoryBy', $nails->uuid, -1);

        expect(ServiceCategory::query()->orderBy('sort_order')->orderBy('id')->pluck('uuid')->all())->toBe([$nails->uuid, $hair->uuid])
            ->and(Service::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Service $x): string => $x->name->get('en'))->all())
            ->toBe(['Manicure', 'Shave', 'Beard trim', 'Haircut', 'Consultation']);

        Livewire::actingAs($owner)
            ->test(Catalog::class)
            ->assertSeeInOrder(['Nails', 'Manicure', 'Hair', 'Shave', 'Beard trim', 'Haircut', 'Consultation']);
    });
});

it('moves a service across category lists, onto a category in the sidebar, and from the menu', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['hair' => $hair, 'nails' => $nails, 's' => $s] = libraryFixture();

        $page = Livewire::actingAs($owner)->test(Catalog::class);

        // Dragged into Nails' list, second place.
        $page->call('moveService', $s['Beard trim']->uuid, 1, $nails->uuid);
        expect(libraryOrder($nails))->toBe(['Manicure', 'Beard trim']);

        // Dropped on "Hair" in the sidebar: appended there.
        $page->call('moveService', $s['Manicure']->uuid, 0, 'end:'.$hair->uuid);
        expect(libraryOrder($hair))->toBe(['Haircut', 'Shave', 'Manicure']);

        // Dropping a service on its own category changes nothing.
        $page->call('moveService', $s['Haircut']->uuid, 0, 'end:'.$hair->uuid);
        expect(libraryOrder($hair))->toBe(['Haircut', 'Shave', 'Manicure']);

        // "Move to category…" from the row menu, to uncategorised.
        $page->call('startMove', $s['Shave']->uuid)
            ->assertSet('moveTarget', $hair->uuid)
            ->assertSee('Move “Shave”')
            ->set('moveTarget', 'none')
            ->call('confirmMove')
            ->assertSet('movingService', null)
            ->assertSet('notice', __('manager_catalog.notices.moved'));

        expect($s['Shave']->refresh()->service_category_id)->toBeNull();

        // An unknown category is reported, not an error page.
        $page->call('moveService', $s['Haircut']->uuid, 0, 'end:'.fake()->uuid())
            ->assertSet('noticeTone', 'danger')
            ->assertSet('notice', __('manager_catalog.errors.not_found'));
    });
});

it('duplicates, switches off, hides, archives and restores from the row menu', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['s' => $s] = libraryFixture();
        $uuid = $s['Haircut']->uuid;

        $page = Livewire::actingAs($owner)->test(Catalog::class)
            ->call('duplicateService', $uuid)
            ->assertSet('notice', __('manager_catalog.notices.duplicated'))
            ->assertSee('Haircut (copy)');

        $copy = Service::query()->where('uuid', '!=', $uuid)->where('name->en', 'Haircut (copy)')->firstOrFail();

        expect($copy->is_active)->toBeFalse()
            ->and($copy->name->get('ar'))->toBe('قص شعر (نسخة)');

        $page->call('setServicePublic', $uuid, false)
            ->call('setServiceOnline', $uuid, false)
            ->call('setServiceActive', $uuid, false);

        $s['Haircut']->refresh();
        expect($s['Haircut']->is_public)->toBeFalse()
            ->and($s['Haircut']->is_online_bookable)->toBeFalse()
            ->and($s['Haircut']->is_active)->toBeFalse();

        $page->call('archiveService', $uuid)
            ->assertDontSee('Haircut</button>', false)
            ->call('setStatus', 'archived')
            ->assertSee('Haircut')
            ->call('restoreService', $uuid)
            ->assertSet('notice', __('manager_catalog.notices.restored'));

        expect($s['Haircut']->refresh()->archived_at)->toBeNull();
    });
});

it('filters by status, visibility and name in any language', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['s' => $s] = libraryFixture();
        $s['Shave']->forceFill(['is_active' => false])->save();
        $s['Manicure']->forceFill(['is_public' => false])->save();

        Livewire::actingAs($owner)->test(Catalog::class)
            ->call('setStatus', 'inactive')
            ->assertSee('Shave')
            ->assertDontSee('Beard trim')
            ->call('clearFilters')
            ->set('visibility', 'hidden')
            ->assertSee('Manicure')
            ->assertDontSee('Consultation')
            ->call('clearFilters')
            // The Arabic name finds it; a language KEY ("en" is in every
            // stored name) finds nothing.
            ->set('search', 'قص')
            ->assertSee('Haircut')
            ->assertDontSee('Shave')
            ->set('search', 'en')
            ->assertSee(__('manager_catalog.empty.no_matches_title'))
            // A filtered list is never sortable.
            ->set('search', 'a')
            ->assertDontSee('x-sortable="moveService"', false);
    });
});

it('quick adds a service to the end of the category being shown', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['hair' => $hair] = libraryFixture();

        Livewire::actingAs($owner)->test(QuickAdd::class, ['category' => $hair->uuid])
            ->set('quickName', 'Kids cut')
            ->set('quickDuration', 20)
            ->set('quickPrice', '10000')
            ->call('add')
            ->assertHasNoErrors()
            ->assertSet('quickName', '')
            ->assertDispatched('catalog-changed');

        expect(libraryOrder($hair))->toBe(['Haircut', 'Beard trim', 'Shave', 'Kids cut'])
            ->and(Service::query()->where('name->en', 'Kids cut')->firstOrFail()->price_minor)->toBe(10000);

        Livewire::actingAs($owner)->test(QuickAdd::class, ['category' => $hair->uuid])
            ->set('quickName', 'Bad')
            ->set('quickPrice', '10.5')
            ->call('add')
            ->assertHasErrors(['quickPrice']);
    });
});

it('creates, edits and archives a category from its drawer, in the enabled languages only', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app(TenantLocales::class)->setEnabled(['en', 'ckb'], 'ckb');
        ['s' => $s] = libraryFixture();

        $drawer = Livewire::actingAs($owner)->test(CategoryEditor::class)
            ->call('create')
            ->assertSee('id="category-text-tab-ckb"', false)
            ->assertSee('id="category-text-tab-en"', false)
            ->assertDontSee('category-text-tab-ar', false)
            ->assertDontSee('CKB')
            // Kurdish is primary here, so the Kurdish name is the required one.
            ->set('categoryName.en', 'Spa')
            ->call('save')
            ->assertHasErrors(['categoryName.ckb'])
            ->set('categoryName.ckb', 'سپا')
            ->set('categoryPublic', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', true);

        $spa = ServiceCategory::query()->where('uuid', $drawer->get('editingCategory'))->firstOrFail();

        expect($spa->name->all())->toBe(['en' => 'Spa', 'ckb' => 'سپا'])
            ->and($spa->is_public)->toBeFalse()
            ->and($spa->sort_order)->toBe(2);

        $hair = $s['Haircut']->category;
        Livewire::actingAs($owner)->test(CategoryEditor::class)
            ->call('edit', $hair?->uuid)
            ->assertSet('categoryName.ar', 'الشعر')
            ->call('archive')
            ->assertDispatched('catalog-changed');

        expect($hair?->refresh()->archived_at)->not->toBeNull()
            ->and($s['Haircut']->refresh()->service_category_id)->toBeNull();

        Livewire::actingAs($owner)->test(Catalog::class)
            ->call('restoreCategory', $hair?->uuid)
            ->assertSet('notice', __('manager_catalog.notices.category_restored'));

        expect($hair?->refresh()->archived_at)->toBeNull();
    });
});

it('renders one text tab per enabled content language in the service drawer, direction per language', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'ar');

        Livewire::actingAs($owner)->test(ServiceEditor::class)
            ->call('create')
            ->call('addVariation')
            ->assertSee('id="service-text-tab-ar"', false)
            ->assertSee('id="service-text-tab-ckb"', false)
            ->assertSee('id="service-text-tab-en"', false)
            ->assertSee('KU')
            ->assertDontSee('CKB')
            // The tab panel and each variation name input carry the language's
            // own direction, from the language registry.
            ->assertSeeHtml('dir="rtl" lang="ckb"')
            ->assertSeeHtml('lang="ar" dir="rtl"')
            ->assertSeeHtml('lang="en" dir="ltr"');
    });
});

it('uploads, reorders and removes service photos through the media kernel', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['s' => $s] = libraryFixture();
        $service = $s['Haircut'];

        $gallery = Livewire::actingAs($owner)
            ->test(MediaGallery::class, ['owner' => 'service', 'ownerUuid' => $service->uuid])
            ->set('photos', [
                UploadedFile::fake()->image('front.png', 120, 90),
                UploadedFile::fake()->image('side.jpg', 120, 90),
            ])
            ->assertHasNoErrors()
            ->assertDispatched('catalog-changed');

        $items = MediaItem::query()->for(MediaOwner::Service, $service->id)->get();
        expect($items)->toHaveCount(2);

        // Second photo becomes the cover.
        $gallery->call('moveMedia', $items[1]->uuid, 0);
        expect(MediaItem::query()->for(MediaOwner::Service, $service->id)->pluck('uuid')->all())
            ->toBe([$items[1]->uuid, $items[0]->uuid]);

        // The library shows the cover as the row thumbnail.
        Livewire::actingAs($owner)->test(Catalog::class)->assertSee($items[1]->path, false);

        // A file that is not an image is refused, and nothing is stored.
        $gallery->set('photos', [UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
            ->assertHasErrors(['photos']);
        expect(MediaItem::query()->count())->toBe(2);

        // Another service's photo cannot be reached through this gallery.
        $other = Livewire::actingAs($owner)
            ->test(MediaGallery::class, ['owner' => 'service', 'ownerUuid' => $s['Shave']->uuid])
            ->call('removeMedia', $items[0]->uuid)
            ->assertSet('noticeTone', 'danger');
        expect(MediaItem::query()->count())->toBe(2);

        $gallery->call('removeMedia', $items[0]->uuid)
            ->assertSet('notice', __('manager_catalog.media.removed'));

        $left = MediaItem::query()->get();
        expect($left)->toHaveCount(1);

        foreach ($left as $item) {
            $item->purge(app(MediaStore::class));
        }
        unset($other);
    });
});

it('refuses photo changes without media.upload, or without the right to edit the owner', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        ['hair' => $hair, 's' => $s] = libraryFixture();

        Livewire::actingAs($this->staffWith([Permission::ServiceView, Permission::ServiceUpdate]))
            ->test(MediaGallery::class, ['owner' => 'service', 'ownerUuid' => $s['Haircut']->uuid])
            ->assertDontSee('type="file"', false)
            ->set('photos', [UploadedFile::fake()->image('front.png', 50, 50)])
            ->assertSet('notice', __('ui.errors.forbidden'));

        // media.upload alone is not enough: a photo belongs to its service or
        // category, and changing it is changing them.
        $uploader = $this->staffWith([Permission::ServiceView, Permission::MediaUpload], 'uploader@alpha.test');

        Livewire::actingAs($uploader)
            ->test(MediaGallery::class, ['owner' => 'service', 'ownerUuid' => $s['Haircut']->uuid])
            ->assertDontSee('type="file"', false)
            ->set('photos', [UploadedFile::fake()->image('front.png', 50, 50)])
            ->assertSet('notice', __('ui.errors.forbidden'));

        Livewire::actingAs($uploader)
            ->test(MediaGallery::class, ['owner' => 'service_category', 'ownerUuid' => $hair->uuid])
            ->set('photos', UploadedFile::fake()->image('cover.png', 50, 50))
            ->assertSet('notice', __('ui.errors.forbidden'));

        expect(MediaItem::query()->count())->toBe(0);
    });
});

it('saves resource requirements from the drawer only with resource.manage, and a duplicate carries them', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['s' => $s] = libraryFixture();
        $room = $this->seedResourceType('Treatment Room');
        $service = $s['Haircut'];

        $rows = fn (Service $of): array => ServiceResourceRequirement::query()->where('service_id', $of->id)->orderBy('id')->get()
            ->map(fn (ServiceResourceRequirement $r): array => [$r->resource_type_id, $r->quantity])->all();

        Livewire::actingAs($owner)->test(ServiceEditor::class)
            ->call('edit', $service->uuid)
            ->call('addRequirement')
            ->set('requirements.0.type', $room->uuid)
            ->set('requirements.0.quantity', 2)
            ->call('saveService')
            ->assertHasNoErrors()
            ->assertDispatched('catalog-changed');

        expect($rows($service))->toBe([[$room->id, 2]]);

        // Read-only without resource.manage: a save leaves them as they were.
        Livewire::actingAs($this->staffWith([Permission::ServiceView, Permission::ServiceUpdate, Permission::ResourceView]))
            ->test(ServiceEditor::class)
            ->call('edit', $service->uuid)
            ->assertSet('requirements', [['type' => $room->uuid, 'quantity' => 2]])
            ->set('requirements.0.quantity', 5)
            ->call('saveService')
            ->assertHasNoErrors();

        expect($rows($service))->toBe([[$room->id, 2]]);

        // Duplicating copies them through Resources, in the copy's transaction.
        Livewire::actingAs($owner)->test(Catalog::class)
            ->call('duplicateService', $service->uuid)
            ->assertSet('notice', __('manager_catalog.notices.duplicated'));

        $copy = Service::query()->latest('id')->firstOrFail();

        expect($copy->id)->not->toBe($service->id)
            ->and($copy->is_active)->toBeFalse()
            ->and($rows($copy))->toBe([[$room->id, 2]]);
    });
});

it('keeps every manager_catalog translation aligned across en, ar and ckb', function (): void {
    $english = Arr::dot(require lang_path('en/manager_catalog.php'));

    foreach (['ar', 'ckb'] as $locale) {
        $translated = Arr::dot(require lang_path("{$locale}/manager_catalog.php"));

        expect(array_keys($translated))->toBe(array_keys($english));

        foreach ($translated as $key => $value) {
            expect($value)->toBeString("{$locale}.manager_catalog.{$key}")->not->toBe('');
        }
    }
});

beforeEach(function (): void {
    // In-process components have no host to supply {center}.
    URL::defaults(['center' => 'library']);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
