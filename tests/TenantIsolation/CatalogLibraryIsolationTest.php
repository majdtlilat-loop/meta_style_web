<?php

declare(strict_types=1);

use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Application\StoreMediaItem;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaStore;
use App\Livewire\Center\Catalog;
use App\Livewire\Center\Catalog\MediaGallery;
use App\Modules\Catalog\Application\CatalogQuery;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager services library — isolation
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §4 — release gate.
|
| Reordering, moving, duplicating and the photo gallery all resolve a uuid
| the BROWSER sent. Resolved on the tenant connection, a uuid from another
| center finds nothing: no move, no copy, no photo, and nothing written there.
|
*/

/**
 * @return array{category: ServiceCategory, first: Service, second: Service}
 */
function isolatedLibrary(string $prefix): array
{
    /** @var ServiceCategory $category */
    $category = ServiceCategory::query()->create([
        'name' => TranslatedText::make('en', $prefix.' Category'), 'is_active' => true, 'is_public' => true, 'sort_order' => 0,
    ]);

    $make = fn (string $name, int $position): Service => Service::query()->create([
        'name' => TranslatedText::make('en', $name), 'service_category_id' => $category->id,
        'duration_minutes' => 30, 'price_minor' => 10000, 'sort_order' => $position,
    ]);

    return ['category' => $category, 'first' => $make($prefix.' First', 0), 'second' => $make($prefix.' Second', 1)];
}

it('finds nothing when one center reorders, moves or copies another center\'s uuids', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');
    $betaOwner = $this->ownerOf($beta['tenant']);

    $foreign = $this->asCenter($alpha['tenant'], fn (): array => isolatedLibrary('Alpha'));
    $this->asCenter($beta['tenant'], fn (): array => isolatedLibrary('Beta'));

    $this->asCenter($beta['tenant'], function () use ($betaOwner, $foreign): void {
        $page = Livewire::actingAs($betaOwner)->test(Catalog::class);

        foreach ([
            ['moveService', [$foreign['second']->uuid, 0]],
            ['moveService', [$foreign['second']->uuid, 0, 'end:'.$foreign['category']->uuid]],
            ['moveServiceBy', [$foreign['second']->uuid, -1]],
            ['moveCategory', [$foreign['category']->uuid, 0]],
            ['duplicateService', [$foreign['first']->uuid]],
            ['archiveService', [$foreign['first']->uuid]],
            ['setServiceActive', [$foreign['first']->uuid, false]],
        ] as [$method, $arguments]) {
            $page->call($method, ...$arguments)
                ->assertSet('notice', __('manager_catalog.errors.not_found'))
                ->assertSet('noticeTone', 'danger');
        }

        // Beta's own library is untouched too.
        expect(Service::query()->count())->toBe(2)
            ->and(Service::query()->orderBy('sort_order')->pluck('name')->map(fn (TranslatedText $n): string => $n->get('en'))->all())
            ->toBe(['Beta First', 'Beta Second']);
    });

    $alphaState = $this->asCenter($alpha['tenant'], fn (): array => Service::query()->orderBy('sort_order')->orderBy('id')->get()
        ->map(fn (Service $s): array => [$s->name->get('en'), $s->sort_order, $s->is_active, $s->archived_at])->all());

    expect($alphaState)->toBe([
        ['Alpha First', 0, true, null],
        ['Alpha Second', 1, true, null],
    ]);
});

it('reads and renumbers only the querying center\'s library', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');
    $alphaOwner = $this->ownerOf($alpha['tenant']);
    $betaOwner = $this->ownerOf($beta['tenant']);

    $this->asCenter($beta['tenant'], fn (): array => isolatedLibrary('Beta'));

    $this->asCenter($alpha['tenant'], function () use ($alphaOwner): void {
        $library = isolatedLibrary('Alpha');

        Livewire::actingAs($alphaOwner)->test(Catalog::class)
            ->call('moveService', $library['second']->uuid, 0)
            ->assertSee('Alpha First')
            ->assertDontSee('Beta First');

        $names = app(CatalogQuery::class)->services([], $alphaOwner)->map(fn (Service $s): string => $s->name->get('en'))->all();

        expect($names)->toBe(['Alpha Second', 'Alpha First']);
    });

    $beta = $this->asCenter($beta['tenant'], fn (): array => [
        'names' => app(CatalogQuery::class)->services([], $betaOwner)->map(fn (Service $s): string => $s->name->get('en'))->all(),
        'categories' => app(CatalogQuery::class)->categories($betaOwner)->count(),
    ]);

    expect($beta)->toBe(['names' => ['Beta First', 'Beta Second'], 'categories' => 1]);
});

it('never shows or removes another center\'s photos through the gallery', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');
    $alphaOwner = $this->ownerOf($alpha['tenant']);
    $betaOwner = $this->ownerOf($beta['tenant']);

    [$serviceUuid, $mediaUuid] = $this->asCenter($alpha['tenant'], function () use ($alphaOwner): array {
        $service = isolatedLibrary('Alpha')['first'];
        $item = app(StoreMediaItem::class)(UploadedFile::fake()->image('alpha.png', 80, 80), MediaOwner::Service, $service->id, $alphaOwner);

        return [$service->uuid, $item->uuid];
    });

    $this->asCenter($beta['tenant'], function () use ($betaOwner, $serviceUuid, $mediaUuid): void {
        Livewire::actingAs($betaOwner)
            ->test(MediaGallery::class, ['owner' => 'service', 'ownerUuid' => $serviceUuid])
            ->assertDontSee('<img', false)
            ->call('removeMedia', $mediaUuid)
            ->assertSet('notice', __('manager_catalog.errors.not_found'));

        expect(MediaItem::query()->count())->toBe(0);
    });

    $remaining = $this->asCenter($alpha['tenant'], function (): int {
        $count = MediaItem::query()->count();

        foreach (MediaItem::query()->get() as $item) {
            $item->purge(app(MediaStore::class));
        }

        return $count;
    });

    expect($remaining)->toBe(1);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
