<?php

declare(strict_types=1);

use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| A center's media, uploaded and served on the center's own host
|--------------------------------------------------------------------------
|
| The tenancy filesystem bootstrapper roots the local and public disks under
| tenants/{key}. A Livewire upload is written by one request and read by the
| next, so both must run inside the same center; and a public image must be
| served from that center's disk, because the global /storage link cannot
| reach it. Only public collections are served, and only on their own host.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('writes a Livewire temporary upload into the center that sent it', function (): void {
    $center = $this->registerCenter('Upload Center', 'owner@upload-center.test');
    $slug = $center['registration']->requested_slug;
    $root = "http://{$slug}.localhost:8000";

    // Under test Livewire always writes to its own `tmp-for-tests` disk. Make
    // that disk behave like `local` in production: rooted per center by the
    // tenancy filesystem bootstrapper — which only happens if the upload
    // request itself ran inside the center.
    config([
        'filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => storage_path('app/tmp-for-tests')],
        'tenancy.filesystem.disks' => [...config('tenancy.filesystem.disks'), 'tmp-for-tests'],
        'tenancy.filesystem.root_override.tmp-for-tests' => '%storage_path%/app/tmp-for-tests/',
    ]);

    // Livewire signs the upload URL relative to the host it was issued on.
    $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], false);
    $central = glob(storage_path('app/tmp-for-tests/livewire-tmp/*')) ?: [];

    $response = $this->post($root.$url, ['files' => [UploadedFile::fake()->image('logo.png', 64, 64)]])->assertOk();
    expect($response->json('paths'))->toBeArray()->toHaveCount(1);

    $this->asCenter($center['tenant'], function (): void {
        expect(Storage::disk('tmp-for-tests')->files('livewire-tmp'))->toHaveCount(1);
    });
    // Nothing new in the central, shared root.
    expect(glob(storage_path('app/tmp-for-tests/livewire-tmp/*')) ?: [])->toBe($central);
});

it('takes an upload from a signed-in manager, inside their center', function (): void {
    $center = $this->registerCenter('Upload Signed In', 'owner@upload-signed-in.test');
    $slug = $center['registration']->requested_slug;
    $owner = $this->ownerOf($center['tenant']);

    config([
        'filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => storage_path('app/tmp-for-tests')],
        'tenancy.filesystem.disks' => [...config('tenancy.filesystem.disks'), 'tmp-for-tests'],
        'tenancy.filesystem.root_override.tmp-for-tests' => '%storage_path%/app/tmp-for-tests/',
    ]);

    $url = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), [], false);

    /*
     * A real signed-in session, not actingAs(): the upload route's throttle
     * keys on `$request->user()`, and loading that user from the session is a
     * TENANT query. With the resolver sorted after the throttle, this answered
     * "Tenant context is missing" for every manager who uploaded anything — an
     * anonymous post, like the test above, never makes that query.
     */
    $response = $this->withSession([
        StanclTenantResolver::SESSION_KEY => $this->publicKeyOf($center['tenant']),
        Auth::guard('web')->getName() => $owner->getAuthIdentifier(),
    ])->post("http://{$slug}.localhost:8000".$url, ['files' => [UploadedFile::fake()->image('offer.png', 64, 64)]]);

    $response->assertOk();
    expect($response->json('paths'))->toBeArray()->toHaveCount(1);

    $this->asCenter($center['tenant'], function (): void {
        expect(Storage::disk('tmp-for-tests')->files('livewire-tmp'))->toHaveCount(1);
    });
});

it('serves a public image from the center host only, with safe headers', function (): void {
    $alpha = $this->registerCenter('Media Alpha', 'owner@media-alpha.test');
    $beta = $this->registerCenter('Media Beta', 'owner@media-beta.test');
    $alphaSlug = $alpha['registration']->requested_slug;
    $betaSlug = $beta['registration']->requested_slug;

    $png = UploadedFile::fake()->image('cover.png', 40, 40)->getContent();
    [$public, $private] = $this->asCenter($alpha['tenant'], fn (): array => [
        app(MediaStore::class)->put(MediaCollection::Catalog, $png, 'png'),
        app(MediaStore::class)->put(MediaCollection::Exports, 'secret,report', 'png'),
    ]);

    $this->get("http://{$alphaSlug}.localhost:8000/media/{$public}")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");

    // Another center's host resolves another center's disk: nothing there.
    $this->get("http://{$betaSlug}.localhost:8000/media/{$public}")->assertNotFound();
    // A private collection, a traversal and an unknown file are not served.
    $this->get("http://{$alphaSlug}.localhost:8000/media/{$private}")->assertNotFound();
    $this->get("http://{$alphaSlug}.localhost:8000/media/catalog/..%2F..%2F.env")->assertNotFound();
    $this->get("http://{$alphaSlug}.localhost:8000/media/catalog/00000000-0000-0000-0000-000000000000.png")->assertNotFound();
});

it('answers byte ranges, so a video plays on Safari and WebKit screens', function (): void {
    $center = $this->registerCenter('Media Ranges', 'owner@media-ranges.test');
    $slug = $center['registration']->requested_slug;

    $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64);
    $video = $this->asCenter($center['tenant'], fn (): string => app(MediaStore::class)->put(MediaCollection::Branding, $bytes, 'mp4'));
    $url = "http://{$slug}.localhost:8000/media/{$video}";

    $this->get($url)
        ->assertOk()
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', 'video/mp4')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox")
        ->assertHeader('Cross-Origin-Resource-Policy', 'same-site');

    // WebKit asks for a slice before it plays anything; it must get exactly that slice.
    $this->get($url, ['Range' => 'bytes=0-9'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 0-9/'.strlen($bytes))
        ->assertHeader('Content-Length', '10')
        ->assertHeader('Content-Type', 'video/mp4');
});

it('addresses public media through the center host', function (): void {
    $center = $this->registerCenter('Media Url Center', 'owner@media-url.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        $item = new MediaItem(['collection' => MediaCollection::Catalog, 'path' => 'catalog/11111111-2222-3333-4444-555555555555.png']);

        $this->app->instance('request', Request::create("http://{$slug}.localhost:8000/manager/catalog"));
        expect($item->url())->toBe("http://{$slug}.localhost:8000/media/catalog/11111111-2222-3333-4444-555555555555.png");
    });
});
