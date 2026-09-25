<?php

declare(strict_types=1);

use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Center\Queue\DisplayMedia;
use App\Livewire\Center\Queue\Displays;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\ManageDisplayMedia;
use App\Modules\Queue\Application\DisplayLanguages;
use App\Modules\Queue\Application\QueueSetupQuery;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueTicketEvent;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Saving a waiting-room screen, in every state it can be in
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §9. The screens editor saved through a closure that did
| not capture the rotation value it computed (`Undefined variable $rotating`),
| and a `rotate` toggle shadowed the `rotate()` action that renews a screen's
| link. Each state a real screen can be in is saved here through the Manager
| component itself: one language, several, rotation off and on, an unusable
| rotation, a screen made before rotation and media existed, no media and
| several. Saving presentation settings never touches the queue.
|
*/

/** @return array<string, mixed> */
function qdsSeed(array $center, array $languages, string $default): array
{
    URL::defaults(['center' => (string) $center['registration']->requested_slug]);
    app()->setLocale('en');
    app(TenantLocales::class)->setEnabled($languages, $default);

    $seed = test()->seedBookableCenter();
    test()->grantQueueEntitlements();
    $seed['display'] = test()->seedDisplay($seed['branch'], 'Hall TV', locale: $default);
    $seed['owner'] = test()->ownerWithCatalogAccess();

    return $seed;
}

it('saves a one-language screen, and a stale rotation toggle cannot make it rotate', function (): void {
    $center = $this->registerCenter('Screens One Language', 'owner@screens-one.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en'], 'en');

        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->set('languageRotation', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        $display = $seed['display']->fresh();
        expect($display?->rotation_enabled)->toBeFalse()
            ->and(app(DisplayLanguages::class)->cycle((bool) $display?->rotation_enabled, $display?->rotationLocales() ?? []))->toBe([]);

        // A new screen saves the same way.
        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('create')
            ->set('name', 'Door TV')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        expect(QueueDisplay::query()->where('name', 'Door TV')->sole()->rotation_enabled)->toBeFalse();
    });
});

it('saves a several-language screen with rotation off, keeping its language', function (): void {
    $center = $this->registerCenter('Screens Rotation Off', 'owner@screens-off.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar', 'ckb'], 'ar');

        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->assertSet('languageRotation', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        expect($seed['display']->fresh()?->rotation_enabled)->toBeFalse()
            ->and($seed['display']->fresh()?->locale)->toBe('ar');
    });
});

it('saves rotation through several languages at a chosen interval, and reopens it as saved', function (): void {
    $center = $this->registerCenter('Screens Rotation On', 'owner@screens-on.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar', 'ckb'], 'en');

        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->set('languageRotation', true)
            ->set('rotationLocales', ['en', 'ar', 'ckb'])
            ->set('rotationSeconds', 12)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        $display = $seed['display']->fresh();
        expect($display?->rotation_enabled)->toBeTrue()
            ->and($display?->rotationLocales())->toBe(['en', 'ar', 'ckb'])
            ->and($display?->rotationSeconds())->toBe(12)
            // The one definition of "rotates" — the same answer the screen gets.
            ->and(app(DisplayLanguages::class)->cycle(true, $display?->rotationLocales() ?? []))->toBe(['en', 'ar', 'ckb']);

        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->assertSet('languageRotation', true)
            ->assertSet('rotationLocales', ['en', 'ar', 'ckb'])
            ->assertSet('rotationSeconds', 12);
    });
});

it('answers an unusable rotation with a message and changes nothing', function (): void {
    $center = $this->registerCenter('Screens Rotation Invalid', 'owner@screens-invalid.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar', 'ckb'], 'en');

        $screens = Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->set('languageRotation', true)
            ->set('rotationLocales', ['en'])
            ->call('save')
            ->assertHasErrors(['rotationLocales' => 'min']);

        foreach ([QueueDisplay::MIN_ROTATION_SECONDS - 1, QueueDisplay::MAX_ROTATION_SECONDS + 1] as $seconds) {
            $screens->set('rotationLocales', ['en', 'ar'])
                ->set('rotationSeconds', $seconds)
                ->call('save')
                ->assertHasErrors(['rotationSeconds']);
        }

        expect($seed['display']->fresh()?->rotation_enabled)->toBeFalse();
    });
});

it('opens and saves a screen made before rotation and media existed', function (): void {
    $center = $this->registerCenter('Screens Older Screen', 'owner@screens-older.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar', 'ckb'], 'ar');

        // Only the columns a pre-feature screen had; the rest are the defaults.
        $older = QueueDisplay::query()->create([
            'branch_id' => $seed['branch']->getKey(),
            'name' => 'Older TV',
            'locale' => 'ar',
            'recent_calls_limit' => 7,
            'sound_enabled' => true,
            'voice_enabled' => true,
            'voice_locales' => ['ar', 'en'],
            'is_active' => true,
        ]);

        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $older->uuid)
            ->assertSet('languageRotation', false)
            ->assertSet('rotationSeconds', 10)
            ->assertSet('rotationLocales', ['en', 'ar', 'ckb'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        $saved = $older->fresh();
        expect($saved?->name)->toBe('Older TV')
            ->and($saved?->locale)->toBe('ar')
            ->and($saved?->recentLimit())->toBe(7)
            ->and($saved?->voiceLocales())->toBe(['ar', 'en'])
            ->and($saved?->rotation_enabled)->toBeFalse();
    });
});

it('lists and saves a screen with no media and with several, and never touches the queue', function (): void {
    $center = $this->registerCenter('Screens Media States', 'owner@screens-media.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar', 'ckb'], 'en');

        // A live queue beside the screen.
        app(CreateWalkInTicket::class)(
            new WalkInRequest(branchUuid: $seed['branch']->uuid, serviceUuids: [$seed['service']->uuid], name: 'Media Guest'),
            $seed['owner'],
            [],
        );
        $events = QueueTicketEvent::query()->count();

        $mediaCount = fn (): int => (int) (collect(Livewire::actingAs($seed['owner'])->test(Displays::class)->viewData('displays'))
            ->firstWhere('uuid', $seed['display']->uuid)['media_count'] ?? -1);

        // No media.
        expect($mediaCount())->toBe(0);
        Livewire::actingAs($seed['owner'])->test(DisplayMedia::class, ['display' => $seed['display']->uuid])->assertOk();
        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)->call('save')->assertHasNoErrors();

        // Several.
        $media = app(ManageDisplayMedia::class);
        $media->configure($seed['display'], true, 8, $seed['owner']);
        $media->add($seed['display'], UploadedFile::fake()->image('first.png', 1280, 720), $seed['owner']);
        $media->add($seed['display'], UploadedFile::fake()->image('second.jpg', 1280, 720), $seed['owner']);

        expect($mediaCount())->toBe(2);
        Livewire::actingAs($seed['owner'])->test(DisplayMedia::class, ['display' => $seed['display']->uuid])->assertOk();
        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->set('languageRotation', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        // Presentation settings and media never write queue history.
        expect(QueueTicketEvent::query()->count())->toBe($events);
    });
});

it('renews a screen link through its own action, which no setting shadows', function (): void {
    $center = $this->registerCenter('Screens Link Renewal', 'owner@screens-link.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar'], 'en');
        $before = $seed['display']->public_key;

        $screens = Livewire::actingAs($seed['owner'])->test(Displays::class);

        // The button runs rotate('<uuid>') — a method, not a property of that name.
        expect(str_contains($screens->html(), "rotate('{$seed['display']->uuid}')"))->toBeTrue()
            ->and(property_exists(Displays::class, 'rotate'))->toBeFalse();

        $screens->call('rotate', $seed['display']->uuid)->assertSet('noticeTone', 'success');

        expect($seed['display']->fresh()?->public_key)->not->toBe($before);
    });
});

it('keeps the stored interval when rotation is switched off over an invalid number', function (): void {
    $center = $this->registerCenter('Screens Hidden Interval', 'owner@screens-hidden.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar'], 'en');
        $seed['display']->forceFill(['rotation_enabled' => true, 'rotation_locales' => ['en', 'ar'], 'rotation_seconds' => 15])->save();

        // Rotation on, a number out of range typed, then rotation off: the field
        // is hidden, so it can no longer block the save — and it is not saved.
        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->set('rotationSeconds', 3)
            ->set('languageRotation', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        $display = $seed['display']->fresh();
        expect($display?->rotation_enabled)->toBeFalse()
            ->and($display?->rotationSeconds())->toBe(15);
    });
});

it('keeps a screen\'s own languages through the center switching one off and on again', function (): void {
    $center = $this->registerCenter('Screens Language Off', 'owner@screens-langoff.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar', 'ckb'], 'en');
        $seed['display']->forceFill(['rotation_enabled' => true, 'rotation_locales' => ['en', 'ar', 'ckb']])->save();

        // The center switches Kurdish off; a manager renames the screen.
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->set('name', 'Hall TV (renamed)')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        $display = $seed['display']->fresh();
        expect($display?->rotation_enabled)->toBeTrue()
            ->and($display?->rotationLocales())->toBe(['en', 'ar', 'ckb'])
            ->and(app(DisplayLanguages::class)->cycle(true, $display?->rotationLocales() ?? []))->toBe(['en', 'ar']);

        // Down to one language: the rotation controls are gone and a save
        // leaves the stored rotation exactly as it was.
        app(TenantLocales::class)->setEnabled(['en'], 'en');
        Livewire::actingAs($seed['owner'])->test(Displays::class)
            ->call('edit', $seed['display']->uuid)
            ->call('save')
            ->assertHasNoErrors();

        expect($seed['display']->fresh()?->rotation_enabled)->toBeTrue()
            ->and($seed['display']->fresh()?->rotationLocales())->toBe(['en', 'ar', 'ckb']);

        // Kurdish back on: the screen rotates through all three again.
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');
        $display = $seed['display']->fresh();
        expect(app(DisplayLanguages::class)->cycle((bool) $display?->rotation_enabled, $display?->rotationLocales() ?? []))->toBe(['en', 'ar', 'ckb']);
    });
});

it('saves the media switch even while the seconds field holds an invalid number', function (): void {
    $center = $this->registerCenter('Screens Media Switch', 'owner@screens-switch.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar'], 'en');

        Livewire::actingAs($seed['owner'])->test(DisplayMedia::class, ['display' => $seed['display']->uuid])
            ->set('slideSeconds', 1)
            ->assertHasErrors(['slideSeconds'])
            ->set('enabled', true)
            ->assertSet('enabled', true);

        // What the switch shows is what was saved; the interval kept its value.
        expect($seed['display']->fresh()?->promo_enabled)->toBeTrue()
            ->and($seed['display']->fresh()?->slideSeconds())->toBe(8);
    });
});

it('shows a caption written only in another language, as the screen does', function (): void {
    $center = $this->registerCenter('Screens Caption Fallback', 'owner@screens-caption.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdsSeed($center, ['en', 'ar'], 'en');
        $media = app(ManageDisplayMedia::class);
        $row = $media->add($seed['display'], UploadedFile::fake()->image('offer.png', 1280, 720), $seed['owner']);
        $media->update($row, ['caption' => ['ar' => 'عرض الأسبوع']], $seed['owner']);

        $items = collect(app(QueueSetupQuery::class)->media($seed['owner'], $seed['display']->uuid));

        expect($items->firstWhere('uuid', $row->uuid)['caption_text'] ?? null)->toBe('عرض الأسبوع');
    });
});

it('starts the feed in the language the screen page started in', function (): void {
    $center = $this->registerCenter('Screens Start Language', 'owner@screens-start.test');
    $slug = (string) $center['registration']->requested_slug;

    $key = $this->asCenter($center['tenant'], function () use ($center): string {
        $seed = qdsSeed($center, ['en', 'ar'], 'en');
        // "Automatic": the screen has no language of its own.
        $seed['display']->forceFill(['locale' => null])->save();

        return $seed['display']->public_key;
    });

    $page = (string) $this->get(app(PlatformHosts::class)->centerUrl($slug, '/q/'.$key.'?locale=ar'))->assertOk()->getContent();

    expect(preg_match('/"feed":"([^"]+)"/', $page, $match))->toBe(1);
    $feed = stripslashes($match[1]);

    // The feed is told the page's language, so its first poll cannot switch
    // the screen into the center default a few seconds after it lit up.
    expect(parse_url($feed, PHP_URL_QUERY))->toContain('locale=ar')
        ->and($this->getJson($feed)->assertOk()->json('data.presentation.start'))->toBe('ar');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
