<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Kernel\Media\Application\ManageMedia;
use App\Kernel\Media\Application\StoreMediaItem;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Media
|--------------------------------------------------------------------------
|
| docs/09-STORAGE.md · docs/13-ROADMAP.md Phase 4 §11.
|
| The first real consumer of Phase 2's MediaStore. Two properties matter most:
| a file never crosses a tenant boundary, and what gets stored is validated on
| its BYTES rather than on what the uploading client claimed.
|
*/

/** A real PNG, so `getimagesize()` has something genuine to parse. */
function pngUpload(string $name = 'photo.png', int $width = 400, int $height = 300): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

it('stores an image and records it against its owner', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $item = $this->asCenter($center['tenant'], function () use ($owner): MediaItem {
        $service = $this->seedCatalog()['service'];

        return app(StoreMediaItem::class)(
            pngUpload(),
            MediaOwner::Service,
            $service->id,
            $owner,
            ['en' => 'A finished haircut'],
        );
    });

    expect($item->owner_type)->toBe(MediaOwner::Service)
        ->and($item->mime_type)->toBe('image/png')
        ->and($item->width)->toBe(400)
        ->and($item->height)->toBe(300)
        ->and($item->alt_text?->get('en'))->toBe('A finished haircut')
        // The generated path is unguessable and lives under the collection —
        // never derived from the uploaded filename.
        ->and($item->path)->toStartWith('catalog/')
        ->and($item->path)->not->toContain('photo');
});

it('keeps one center\'s files unreachable from another', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $alphaOwner = $this->ownerOf($alpha['tenant']);

    $item = $this->asCenter($alpha['tenant'], function () use ($alphaOwner): MediaItem {
        $service = $this->seedCatalog()['service'];

        return app(StoreMediaItem::class)(pngUpload(), MediaOwner::Service, $service->id, $alphaOwner);
    });

    $alphaPath = $item->path;

    // The same disk-relative path, read from inside Beta. The disks are rooted
    // at tenants/{key}/, so this is a different absolute location entirely.
    $visibleToBeta = $this->asCenter($beta['tenant'], fn (): bool => app(MediaStore::class)
        ->exists(MediaCollection::Catalog, $alphaPath));

    $visibleToAlpha = $this->asCenter($alpha['tenant'], fn (): bool => app(MediaStore::class)
        ->exists(MediaCollection::Catalog, $alphaPath));

    expect($visibleToAlpha)->toBeTrue()
        ->and($visibleToBeta)->toBeFalse();

    // And the row itself lives in Alpha's database, so Beta cannot even see it.
    $rowsInBeta = $this->asCenter($beta['tenant'], fn (): int => MediaItem::query()->count());

    expect($rowsInBeta)->toBe(0);
});

it('refuses to touch storage with no tenant bound', function (): void {
    // Fail-closed: without this, a write with no tenant lands in the shared
    // storage root where another center could read it.
    expect(fn () => app(MediaStore::class)->put(MediaCollection::Catalog, 'contents'))
        ->toThrow(TenantNotResolved::class);
});

it('rejects a file that is not an image, whatever it calls itself', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];

        // A PHP file wearing a .png name, with a respectable client mime type.
        // The client's claims are attacker-controlled; getimagesize() is not.
        $disguised = UploadedFile::fake()->createWithContent(
            'photo.png',
            "<?php echo 'pwned'; ?>",
        );

        expect(fn () => app(StoreMediaItem::class)($disguised, MediaOwner::Service, $service->id, $owner))
            ->toThrow(ValidationException::class);
    });
});

it('rejects an image type outside the allowed list', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];

        $gif = UploadedFile::fake()->image('animation.gif');

        expect(fn () => app(StoreMediaItem::class)($gif, MediaOwner::Service, $service->id, $owner))
            ->toThrow(ValidationException::class, 'JPEG, PNG or WebP');
    });
});

it('rejects an image beyond the dimension limit', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];

        config()->set('metastyle.catalog.media.max_dimension', 200);

        expect(fn () => app(StoreMediaItem::class)(pngUpload('big.png', 400, 400), MediaOwner::Service, $service->id, $owner))
            ->toThrow(ValidationException::class, '200 pixels');
    });
});

it('caps how many images an owner may hold', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $catalog = $this->seedCatalog();
        $store = app(StoreMediaItem::class);

        // A department gets one image: a menu heading with six photographs is
        // one nobody can scan.
        $store(pngUpload(), MediaOwner::Department, $catalog['department']->id, $owner);

        expect(MediaOwner::Department->maxItems())->toBe(1)
            ->and(MediaOwner::Service->allowsMultiple())->toBeTrue();

        expect(fn () => $store(pngUpload(), MediaOwner::Department, $catalog['department']->id, $owner))
            ->toThrow(ValidationException::class, 'at most 1 image');
    });
});

it('deletes the row and the file together', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];

        $item = app(StoreMediaItem::class)(pngUpload(), MediaOwner::Service, $service->id, $owner);
        $path = $item->path;

        expect(app(MediaStore::class)->exists(MediaCollection::Catalog, $path))->toBeTrue();

        app(ManageMedia::class)->delete($item, $owner);

        // A row without a file renders a broken image; a file without a row is
        // storage nobody will ever reclaim.
        expect(MediaItem::query()->count())->toBe(0)
            ->and(app(MediaStore::class)->exists(MediaCollection::Catalog, $path))->toBeFalse();
    });
});

it('replaces an image by storing a new one and dropping the old', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];

        $first = app(StoreMediaItem::class)(pngUpload('one.png'), MediaOwner::Service, $service->id, $owner);
        $firstPath = $first->path;

        app(ManageMedia::class)->delete($first, $owner);

        $second = app(StoreMediaItem::class)(pngUpload('two.png'), MediaOwner::Service, $service->id, $owner);

        // A new generated path, not the old one reused — otherwise a cached or
        // shared URL would silently start showing a different picture.
        expect($second->path)->not->toBe($firstPath)
            ->and(app(MediaStore::class)->exists(MediaCollection::Catalog, $firstPath))->toBeFalse()
            ->and(app(MediaStore::class)->exists(MediaCollection::Catalog, $second->path))->toBeTrue();
    });
});

it('reorders a gallery without letting another owner\'s image in', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $catalog = $this->seedCatalog();
        $store = app(StoreMediaItem::class);

        $a = $store(pngUpload('a.png'), MediaOwner::Service, $catalog['service']->id, $owner);
        $b = $store(pngUpload('b.png'), MediaOwner::Service, $catalog['service']->id, $owner);
        $foreign = $store(pngUpload('c.png'), MediaOwner::Department, $catalog['department']->id, $owner);

        app(ManageMedia::class)->reorder(
            MediaOwner::Service,
            $catalog['service']->id,
            // The foreign uuid is scoped out by the query, not trusted.
            [$b->uuid, $a->uuid, $foreign->uuid],
            $owner,
        );

        $order = MediaItem::query()->for(MediaOwner::Service, $catalog['service']->id)
            ->pluck('uuid')->all();

        expect($order)->toBe([$b->uuid, $a->uuid])
            ->and($foreign->refresh()->sort_order)->toBe(0);
    });
});

it('produces a public URL for catalog images and none for private ones', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $service = $this->seedCatalog()['service'];

        $public = app(StoreMediaItem::class)(pngUpload(), MediaOwner::Service, $service->id, $owner);

        expect($public->url())->toBeString()->toContain($public->path);

        // A private collection has no public URL by construction — access goes
        // through a permission check and a signed URL instead.
        $private = new MediaItem(['collection' => MediaCollection::Exports, 'path' => 'exports/x.csv']);

        expect($private->url())->toBeNull()
            ->and(MediaCollection::Catalog->isPublic())->toBeTrue()
            ->and(MediaCollection::Exports->isPublic())->toBeFalse();
    });
});

it('never serialises the stored path to a client', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $json = $this->asCenter($center['tenant'], function () use ($owner): string {
        $service = $this->seedCatalog()['service'];

        return app(StoreMediaItem::class)(pngUpload(), MediaOwner::Service, $service->id, $owner)->toJson();
    });

    // Handing a client the path invites it to build its own URLs, which is how
    // a private collection ends up publicly linked.
    expect($json)->not->toContain('"path"')
        ->toContain('uuid');
});

it('requires the upload permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $service = $this->seedCatalog()['service'];

        /** @var User $staff */
        $staff = User::query()->create([
            'name' => 'No Access',
            'email' => 'noaccess@alpha.test',
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => true,
        ]);

        $staff->forgetPermissionCache();

        expect(fn () => app(StoreMediaItem::class)(pngUpload(), MediaOwner::Service, $service->id, $staff))
            ->toThrow(AuthorizationException::class);
    });
});

it('shows a service image on the public menu', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->publishMenu();
        $service = $this->seedCatalog()['service'];

        app(StoreMediaItem::class)(
            pngUpload(),
            MediaOwner::Service,
            $service->id,
            $owner,
            ['en' => 'Finished cut'],
        );
    });

    // Phase 15: the public menu API answers on the center's OWN host, with its
    // public slug in the path (ADR-076). A bare path on the platform host is
    // "No center is published at this address".
    $slug = (string) $center['registration']->requested_slug;

    $images = $this->getJson(app(PlatformHosts::class)->centerUrl($slug, '/api/v1/menu/'.$slug))
        ->assertOk()
        ->json('data.services.0.images');

    expect($images)->toHaveCount(1)
        ->and($images[0]['alt'])->toBe('Finished cut')
        ->and($images[0]['url'])->toBeString();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();

    // The tenancy filesystem bootstrapper roots the disks per tenant, so the
    // fake files land in the real storage tree. Clear what the test wrote.
    Storage::disk('public')->deleteDirectory('tenants');
});
