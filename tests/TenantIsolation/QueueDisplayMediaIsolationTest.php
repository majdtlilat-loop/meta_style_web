<?php

declare(strict_types=1);

use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Center\Queue\DisplayMedia;
use App\Modules\Queue\Application\Actions\ManageDisplayMedia;
use App\Modules\Queue\Application\QueueSetupQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Screen promotional media — tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md, docs/17-QUEUE.md §9. A screen's playlist lives in its
| center's own database and its files on its center's own disk. Another
| center's screen, playlist item, preview or file is simply not there.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

/**
 * @return array{display: string, key: string, item: string, url: string}
 */
function qdmIsolationScreen(string $name): array
{
    $seed = test()->seedBookableCenter();
    test()->grantQueueEntitlements();
    $owner = test()->ownerWithCatalogAccess();

    $display = test()->seedDisplay($seed['branch'], $name.' TV');
    $item = app(ManageDisplayMedia::class)->add($display, UploadedFile::fake()->image($name.'.png', 640, 360), $owner);
    app(ManageDisplayMedia::class)->configure($display, true, 8, $owner);

    return [
        'display' => $display->uuid,
        'key' => $display->public_key,
        'item' => $item->uuid,
        'url' => '/media/'.$item->mediaItem?->path,
    ];
}

it('never shows, previews or changes another center\'s screen media', function (): void {
    $alpha = $this->registerCenter('Screens Alpha', 'owner@screens-alpha.test');
    $beta = $this->registerCenter('Screens Beta', 'owner@screens-beta.test');
    $alphaSlug = (string) $alpha['registration']->requested_slug;
    $betaSlug = (string) $beta['registration']->requested_slug;

    $alphaRefs = $this->asCenter($alpha['tenant'], fn (): array => qdmIsolationScreen('Alpha'));
    $betaRefs = $this->asCenter($beta['tenant'], fn (): array => qdmIsolationScreen('Beta'));

    $this->asCenter($alpha['tenant'], function () use ($alphaSlug, $alphaRefs, $betaRefs): void {
        URL::defaults(['center' => $alphaSlug]);
        $owner = $this->ownerWithCatalogAccess();

        // Beta's screen and item are not found from Alpha — not forbidden.
        expect(fn () => app(QueueSetupQuery::class)->display($owner, $betaRefs['display']))->toThrow(ModelNotFoundException::class)
            ->and(fn () => app(QueueSetupQuery::class)->mediaItem($owner, $alphaRefs['display'], $betaRefs['item']))->toThrow(ModelNotFoundException::class);

        Livewire::actingAs($owner)->test(DisplayMedia::class, ['display' => $alphaRefs['display']])
            ->call('removeItem', $betaRefs['item'])
            ->assertSet('notice', __('manager_queue.errors.not_found'))
            ->assertDontSee($betaRefs['url']);

        // The preview answers "not here" for Beta's screen on Alpha's host.
        $this->actingAs($owner);
        $this->get("http://{$alphaSlug}.localhost:8000/manager/queue/displays/{$betaRefs['display']}/preview")->assertNotFound();
    });

    // Beta's item still exists in Beta.
    $this->asCenter($beta['tenant'], function () use ($betaRefs): void {
        $owner = $this->ownerWithCatalogAccess();
        expect(app(QueueSetupQuery::class)->mediaItem($owner, $betaRefs['display'], $betaRefs['item'])->uuid)->toBe($betaRefs['item']);
    });

    $hosts = app(PlatformHosts::class);

    // Beta's playlist is not published by Alpha's screen, nor Beta's file served on Alpha's host.
    $alphaFeed = (string) $this->getJson($hosts->centerUrl($alphaSlug, '/api/v1/queue/'.$alphaSlug.'/displays/'.$alphaRefs['key']))->assertOk()->getContent();

    expect(str_contains($alphaFeed, basename($betaRefs['url'])))->toBeFalse('Another center\'s file reached this screen.')
        ->and(str_contains($alphaFeed, basename($alphaRefs['url'])))->toBeTrue();

    $this->get($hosts->centerUrl($alphaSlug, $betaRefs['url']))->assertNotFound();
    $this->get($hosts->centerUrl($betaSlug, $betaRefs['url']))->assertOk();

    // Beta's screen key on Alpha's host is not a screen.
    $this->getJson($hosts->centerUrl($alphaSlug, '/api/v1/queue/'.$alphaSlug.'/displays/'.$betaRefs['key']))->assertNotFound();

    foreach ([$alpha, $beta] as $center) {
        $this->asCenter($center['tenant'], fn () => Storage::disk('public')->deleteDirectory('branding'));
    }
});
