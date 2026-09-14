<?php

declare(strict_types=1);

use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Application\StoreMediaItem;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Catalog, media and menu isolation
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §4 — release gate.
|
| Phase 4 is the first phase whose data a stranger can reach. Every new table
| gets a case here, because "anything touching tenant data needs a case in
| tests/TenantIsolation" is the rule that makes this suite worth running.
|
*/

it('returns only the querying center\'s services', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], fn () => Service::query()->create([
        'name' => TranslatedText::make('en', 'Alpha Haircut'),
        'duration_minutes' => 30, 'price_minor' => 20000,
    ]));

    $this->asCenter($beta['tenant'], fn () => Service::query()->create([
        'name' => TranslatedText::make('en', 'Beta Massage'),
        'duration_minutes' => 60, 'price_minor' => 50000,
    ]));

    $inAlpha = $this->asCenter($alpha['tenant'], fn (): array => Service::query()->get()
        ->map(fn (Service $s): string => $s->name->get('en'))->all());

    $inBeta = $this->asCenter($beta['tenant'], fn (): array => Service::query()->get()
        ->map(fn (Service $s): string => $s->name->get('en'))->all());

    expect($inAlpha)->toBe(['Alpha Haircut'])
        ->and($inBeta)->toBe(['Beta Massage']);
});

it('keeps departments, categories, variations and add-ons per center', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], fn () => $this->seedCatalog());

    $counts = $this->asCenter($beta['tenant'], fn (): array => [
        'departments' => Department::query()->count(),
        'categories' => ServiceCategory::query()->count(),
        'variations' => ServiceVariation::query()->count(),
        'addons' => ServiceAddon::query()->count(),
    ]);

    // Every one of them is a fresh table in Beta's own database.
    expect($counts)->toBe([
        'departments' => 0, 'categories' => 0, 'variations' => 0, 'addons' => 0,
    ]);
});

it('keeps internal notes inside the center that wrote them', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->seedCatalog()['service']->addInternalNote('Alpha cost price is 8,000.');
    });

    $notesInBeta = $this->asCenter(
        $beta['tenant'],
        fn (): int => InternalNote::query()->count(),
    );

    expect($notesInBeta)->toBe(0);
});

it('gives each center its own menu versions', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $alphaVersion = $this->asCenter($alpha['tenant'], function (): MenuVersion {
        $published = MenuVersion::query()->published()->firstOrFail();

        $published->forceFill(['template_key' => 'luxury'])->save();

        return $published;
    });

    $betaTemplate = $this->asCenter(
        $beta['tenant'],
        fn (): string => MenuVersion::query()->published()->firstOrFail()->template_key,
    );

    // Both centers have version 1, and they are different rows in different
    // databases — the unique index on `version` is per tenant, not global.
    expect($alphaVersion->template_key)->toBe('luxury')
        ->and($betaTemplate)->toBe('minimal');
});

it('never lets one center read another\'s media rows or files', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $alphaOwner = $this->ownerOf($alpha['tenant']);

    $path = $this->asCenter($alpha['tenant'], function () use ($alphaOwner): string {
        $service = $this->seedCatalog()['service'];

        return app(StoreMediaItem::class)(
            UploadedFile::fake()->image('alpha.png', 200, 200),
            MediaOwner::Service,
            $service->id,
            $alphaOwner,
        )->path;
    });

    $fromBeta = $this->asCenter($beta['tenant'], fn (): array => [
        'rows' => MediaItem::query()->count(),
        'file' => app(MediaStore::class)->exists(MediaCollection::Catalog, $path),
        'contents' => app(MediaStore::class)->get(MediaCollection::Catalog, $path),
    ]);

    expect($fromBeta['rows'])->toBe(0)
        ->and($fromBeta['file'])->toBeFalse()
        ->and($fromBeta['contents'])->toBeNull();
});

it('resolves different storage roots for two centers', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $alphaRoot = $this->asCenter(
        $alpha['tenant'],
        fn (): string => app(MediaStore::class)->absolutePath(MediaCollection::Catalog, 'x.png'),
    );

    $betaRoot = $this->asCenter(
        $beta['tenant'],
        fn (): string => app(MediaStore::class)->absolutePath(MediaCollection::Catalog, 'x.png'),
    );

    expect($alphaRoot)->not->toBe($betaRoot)
        ->and($alphaRoot)->toContain($alpha['tenant']->id)
        ->and($betaRoot)->toContain($beta['tenant']->id);
});

it('never exposes one center\'s catalog through another\'s public menu', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->publishMenu();
        $this->seedCatalog();
    });

    $this->asCenter($beta['tenant'], function (): void {
        $this->publishMenu();

        Service::query()->create([
            'name' => TranslatedText::make('en', 'Beta Secret Treatment'),
            'duration_minutes' => 45, 'price_minor' => 75000,
            'is_active' => true, 'is_public' => true,
        ]);
    });

    // The guest-facing surface is where a leak would be worst, because nobody
    // needs an account to look.
    $body = (string) $this->getJson('/api/v1/menu/'.$this->publicKeyOf($alpha['tenant']))
        ->assertOk()->getContent();

    expect($body)->toContain('Haircut')
        ->not->toContain('Beta Secret Treatment');
});

it('leaves no tenant bound after a guest menu request', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->publishMenu();
        $this->seedCatalog();
    });

    $this->getJson('/api/v1/menu/'.$this->publicKeyOf($alpha['tenant']))->assertOk();

    $context = app(TenantContext::class);

    expect($context->isBound())->toBeFalse()
        ->and($context->id())->toBeNull();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();

    Storage::disk('public')->deleteDirectory('tenants');
});
