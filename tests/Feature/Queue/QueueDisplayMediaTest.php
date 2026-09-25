<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\ManageDisplayMedia;
use App\Modules\Queue\Application\Actions\SaveDisplay;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueDisplayMedia;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Waiting-room screens: promotional media and language rotation
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §9. A screen may play the center's own images and videos
| beside the queue and cycle its labels through the CENTER's languages. Both
| are presentation of that screen: files go through the media kernel (bytes,
| never markup), the public payload is an allow-list, and nothing here may
| touch a ticket, a call or an announcement.
|
*/

function qdmSeed(): array
{
    $seed = test()->seedBookableCenter();
    test()->grantQueueEntitlements();

    app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception');
    $seed['reception']->forceFill([
        'name' => TranslatedText::fromArray(['en' => 'Reception', 'ar' => 'الاستقبال', 'ckb' => 'پێشوازی']),
    ])->save();

    return $seed;
}

function qdmCall(array $seed, User $owner, string $name = 'Sara Ahmed', string $phone = '+9647501234567'): QueueTicket
{
    $ticket = app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $name,
            phone: $phone,
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
    )['ticket'];

    app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

    return $ticket->fresh() ?? $ticket;
}

function qdmMp4(string $name = 'clip.mp4'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
}

function qdmFeed(array $center, QueueDisplay $display, string $query = ''): string
{
    $slug = (string) $center['registration']->requested_slug;

    return app(PlatformHosts::class)->centerUrl($slug, '/api/v1/queue/'.$slug.'/displays/'.$display->public_key.$query);
}

function qdmPage(array $center, QueueDisplay $display, string $query = ''): string
{
    return app(PlatformHosts::class)->centerUrl((string) $center['registration']->requested_slug, '/q/'.$display->public_key.$query);
}

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('keeps several images and a video on one screen, in order, checked on their bytes', function (): void {
    $center = $this->registerCenter('Media Screens', 'owner@qdm-media.test');

    $this->asCenter($center['tenant'], function (): void {
        $seed = qdmSeed();
        $owner = $this->ownerWithCatalogAccess();
        $display = $this->seedDisplay($seed['branch']);
        $media = app(ManageDisplayMedia::class);

        $first = $media->add($display, UploadedFile::fake()->image('first.png', 1280, 720), $owner);
        $second = $media->add($display, UploadedFile::fake()->image('second.jpg', 1920, 1080), $owner);
        $clip = $media->add($display, qdmMp4(), $owner);

        $rows = $display->promoMedia()->with('mediaItem')->get();

        expect($rows->pluck('uuid')->all())->toBe([$first->uuid, $second->uuid, $clip->uuid])
            ->and($rows->pluck('sort_order')->all())->toBe([0, 1, 2])
            ->and($rows[2]->isVideo())->toBeTrue()
            ->and($rows[2]->mediaItem?->mime_type)->toBe('video/mp4')
            // Owned by THIS screen, in the public branding collection.
            ->and($rows[0]->mediaItem?->owner_type)->toBe(MediaOwner::QueueDisplay)
            ->and($rows[0]->mediaItem?->owner_id)->toBe($display->id);

        // Never markup, never a script with a friendly name, never SVG.
        $refused = [
            UploadedFile::fake()->createWithContent('poster.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('clip.mp4', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "owned"; ?>'),
            UploadedFile::fake()->createWithContent('page.html', '<iframe src="https://example.test"></iframe>'),
        ];

        foreach ($refused as $file) {
            expect(fn () => $media->add($display, $file, $owner))->toThrow(ValidationException::class);
        }

        expect($display->promoMedia()->count())->toBe(3)
            ->and(MediaItem::query()->for(MediaOwner::QueueDisplay, $display->id)->count())->toBe(3);

        // One intent at a time: the video to the front, then one step later.
        expect($media->move($clip, 0, $owner))->toBe(0);
        expect($display->promoMedia()->pluck('uuid')->all())->toBe([$clip->uuid, $first->uuid, $second->uuid]);

        expect($media->step($clip, 1, $owner))->toBe(1)
            // Out of range is clamped, never trusted.
            ->and($media->move($first, 99, $owner))->toBe(2);
        expect($display->promoMedia()->pluck('uuid')->all())->toBe([$clip->uuid, $second->uuid, $first->uuid])
            ->and($display->promoMedia()->pluck('sort_order')->all())->toBe([0, 1, 2]);

        // Pause, caption per ENABLED language, alt text for an image.
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $second->forceFill(['caption' => TranslatedText::fromArray(['ckb' => 'دەقی کوردی'])])->save();

        $media->update($second, [
            'enabled' => false,
            'caption' => ['en' => "  Summer\x07 offer  ", 'ar' => 'عرض الصيف', 'ckb' => 'ignored while switched off'],
            'alt' => ['en' => 'A chair by the window'],
        ], $owner);

        $second->refresh();

        expect($second->is_enabled)->toBeFalse()
            ->and($second->caption?->in('en'))->toBe('Summer  offer')
            ->and($second->caption?->in('ar'))->toBe('عرض الصيف')
            // Switching Kurdish off never deleted what was written in it.
            ->and($second->caption?->in('ckb'))->toBe('دەقی کوردی')
            ->and($second->mediaItem?->alt_text?->in('en'))->toBe('A chair by the window');

        // Removing takes the row AND the file, and closes the gap.
        $path = $clip->mediaItem?->path;
        $media->remove($clip->fresh() ?? $clip, $owner);

        expect(QueueDisplayMedia::query()->where('uuid', $clip->uuid)->exists())->toBeFalse()
            ->and(app(MediaStore::class)->exists(MediaCollection::Branding, (string) $path))->toBeFalse()
            ->and($display->promoMedia()->pluck('sort_order')->all())->toBe([0, 1]);

        expect(TenantAuditLog::query()->where('action', 'queue.display.media_added')->count())->toBe(3)
            ->and(TenantAuditLog::query()->where('action', 'queue.display.media_removed')->count())->toBe(1);

        Storage::disk('public')->deleteDirectory('branding');
    });
});

it('lets only a screen manager of that branch, with screens, change its media', function (): void {
    $center = $this->registerCenter('Media Guard', 'owner@qdm-guard.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = qdmSeed();
        $owner = $this->ownerWithCatalogAccess();
        $other = $this->seedBranch('Mansour');
        $display = $this->seedDisplay($other, 'Mansour TV');
        $media = app(ManageDisplayMedia::class);

        $item = $media->add($display, UploadedFile::fake()->image('a.png', 640, 360), $owner);

        // A manager of the main branch only.
        $scoped = $this->staffWith([Permission::QueueDisplayManage, Permission::MediaUpload], 'scoped@qdm-guard.test');
        $scoped->forceFill(['all_branches' => false])->save();
        $scoped->syncBranchScope([(int) $seed['branch']->id]);
        $scoped->forgetPermissionCache();

        expect(fn () => $media->add($display, UploadedFile::fake()->image('b.png', 640, 360), $scoped))->toThrow(AuthorizationException::class)
            ->and(fn () => $media->remove($item, $scoped))->toThrow(AuthorizationException::class)
            ->and(fn () => $media->configure($display, true, 8, $scoped))->toThrow(AuthorizationException::class);

        // Queue display rights without media rights: nothing is half removed.
        $noMedia = $this->staffWith([Permission::QueueDisplayManage], 'nomedia@qdm-guard.test');
        expect(fn () => $media->remove($item, $noMedia))->toThrow(AuthorizationException::class);
        expect(QueueDisplayMedia::query()->whereKey($item->id)->exists())->toBeTrue();

        // Without screens in the plan, a playlist cannot be built.
        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'queue_display'],
            ['mode' => OverrideMode::Revoke],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        // A fresh Action, as the next request would build: the one above memoised the plan.
        expect(fn () => app(ManageDisplayMedia::class)->add($display, UploadedFile::fake()->image('c.png', 640, 360), $owner))->toThrow(EntitlementRequired::class);

        Storage::disk('public')->deleteDirectory('branding');
    });
});

it('rotates only through the languages the center has switched on', function (): void {
    $center = $this->registerCenter('Rotation Center', 'owner@qdm-rotation.test');

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qdmSeed();
        $owner = $this->ownerWithCatalogAccess();
        $save = app(SaveDisplay::class);

        $display = $save([
            'branch' => $seed['branch']->uuid,
            'name' => 'Door TV',
            'locale' => 'en',
            'rotation_enabled' => true,
            'rotation_locales' => ['en', 'ar', 'ckb'],
            'rotation_seconds' => 3,
        ], $owner);

        // Clamped, not trusted.
        expect($display->rotation_seconds)->toBe(QueueDisplay::MIN_ROTATION_SECONDS);

        $display = $save(['rotation_seconds' => 500], $owner, $display);
        expect($display->rotation_seconds)->toBe(QueueDisplay::MAX_ROTATION_SECONDS);

        $display = $save(['rotation_seconds' => 10], $owner, $display);

        // One language is nothing to rotate; an unknown one is refused.
        expect(fn () => $save(['rotation_enabled' => true, 'rotation_locales' => ['en']], $owner, $display))
            ->toThrow(QueueFailed::class, 'Choose at least two languages to rotate.')
            ->and(fn () => $save(['rotation_locales' => ['en', 'xx']], $owner, $display))
            ->toThrow(QueueFailed::class, 'That language is not one this platform knows.');

        // A language the center has switched off cannot be switched ON here.
        // One the screen already had is kept (disabling never deletes), so
        // re-saving the screen never fails on it.
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $kept = $save(['rotation_locales' => ['en', 'ar', 'ckb']], $owner, $display);
        expect($kept->rotationLocales())->toBe(['en', 'ar', 'ckb']);

        $display = $save(['rotation_locales' => ['en', 'ar']], $owner, $kept);
        expect(fn () => $save(['rotation_locales' => ['en', 'ckb']], $owner, $display))
            ->toThrow(QueueFailed::class, 'That language is not switched on for this center.');

        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');
        $display = $save(['rotation_locales' => ['en', 'ar', 'ckb']], $owner, $display);

        qdmCall($seed, $owner);

        return $display->fresh() ?? $display;
    });

    $feed = $this->getJson(qdmFeed($center, $display))->assertOk();
    $presentation = $feed->json('data.presentation');

    expect($presentation['rotation'])->toBe(['enabled' => true, 'seconds' => 10, 'locales' => ['en', 'ar', 'ckb']])
        ->and($presentation['start'])->toBe('en')
        // Direction per language, from the registry: EN LTR, AR and KU RTL.
        ->and($presentation['languages']['en']['dir'])->toBe('ltr')
        ->and($presentation['languages']['ar']['dir'])->toBe('rtl')
        ->and($presentation['languages']['ckb']['dir'])->toBe('rtl')
        // Kurdish is labelled KU, never CKB.
        ->and($presentation['languages']['ckb']['label'])->toBe('KU')
        // Every label, server-rendered, in every language the screen cycles.
        ->and($presentation['languages']['ar']['text']['now_calling'])->toBe(__('queue_public.now_calling', [], 'ar'))
        ->and($presentation['languages']['ckb']['text']['recently_called'])->toBe(__('queue_public.recently_called', [], 'ckb'))
        ->and($presentation['languages']['ar']['text']['state_serving'])->toBe(__('queue_public.state_serving', [], 'ar'))
        // The destination in each language, so a switch needs no request.
        ->and($feed->json('data.now_calling.destination_names'))->toBe(['en' => 'Reception', 'ar' => 'الاستقبال', 'ckb' => 'پێشوازی']);

    // The center switches Kurdish off: gone from the screen at once, and the
    // screen's own choice is kept for when it comes back.
    $this->asCenter($center['tenant'], fn () => app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en'));

    $narrowed = $this->getJson(qdmFeed($center, $display))->assertOk();

    expect($narrowed->json('data.presentation.rotation.locales'))->toBe(['en', 'ar'])
        ->and($narrowed->json('data.presentation.languages'))->not->toHaveKey('ckb')
        ->and(array_keys($narrowed->json('data.now_calling.destination_names')))->toBe(['en', 'ar'])
        ->and($this->asCenter($center['tenant'], fn () => $display->fresh()?->rotationLocales()))->toBe(['en', 'ar', 'ckb']);

    // A screen set to a language the center no longer publishes starts in the
    // center's own language instead.
    $this->asCenter($center['tenant'], fn () => $display->forceFill(['locale' => 'ckb', 'rotation_enabled' => false])->save());

    $single = $this->getJson(qdmFeed($center, $display))->assertOk();

    expect($single->json('data.presentation.rotation.enabled'))->toBeFalse()
        ->and($single->json('data.presentation.start'))->toBe('en')
        ->and($single->json('data.display.locale'))->toBe('en');
});

it('shows only what plays, as an allow-list, and only when it changed', function (): void {
    $center = $this->registerCenter('Playlist Center', 'owner@qdm-playlist.test');

    [$display, $uuids] = $this->asCenter($center['tenant'], function (): array {
        $seed = qdmSeed();
        $owner = $this->ownerWithCatalogAccess();
        $display = $this->seedDisplay($seed['branch']);
        $media = app(ManageDisplayMedia::class);

        $shown = $media->add($display, UploadedFile::fake()->image('shown.png', 1280, 720), $owner);
        $paused = $media->add($display, UploadedFile::fake()->image('paused.png', 1280, 720), $owner);
        $clip = $media->add($display, qdmMp4(), $owner);

        $media->update($shown, ['caption' => ['en' => 'New season', 'ar' => 'موسم جديد']], $owner);
        $media->update($paused, ['enabled' => false], $owner);

        qdmCall($seed, $owner, 'Sara Ahmed', '+9647509876543');

        return [$display, [
            $shown->uuid, $paused->uuid, $clip->uuid, $display->uuid, $seed['branch']->uuid,
            (string) $shown->mediaItem?->uuid, (string) $paused->mediaItem?->uuid,
        ]];
    });

    // Switched off, nothing plays even with items in the list.
    $off = $this->getJson(qdmFeed($center, $display))->assertOk();
    expect($off->json('data.presentation.promo.enabled'))->toBeFalse()
        ->and($off->json('data.presentation.promo.items'))->toBe([]);

    $this->asCenter($center['tenant'], fn () => app(ManageDisplayMedia::class)->configure($display, true, 2, $this->ownerWithCatalogAccess()));

    $on = $this->getJson(qdmFeed($center, $display))->assertOk();
    $presentation = $on->json('data.presentation');
    $promo = $on->json('data.presentation.promo');

    // An allow-list, pinned as exact key sets: a field added later fails here
    // instead of reaching a television unnoticed.
    expect(array_keys($presentation))->toBe(['start', 'rotation', 'languages', 'promo', 'version'])
        ->and(array_keys($presentation['rotation']))->toBe(['enabled', 'seconds', 'locales'])
        ->and(array_keys($promo))->toBe(['enabled', 'slide_seconds', 'items'])
        ->and(array_keys($on->json('data.now_calling')))->toBe([
            'announcement_id', 'number', 'destination_code', 'destination_name', 'destination_names', 'department_name', 'called_at', 'state',
        ]);

    foreach ($presentation['languages'] as $language) {
        expect(array_keys($language))->toBe(['dir', 'label', 'branch', 'text'])
            ->and(array_keys($language['text']))->toBe([
                'now_calling', 'recently_called', 'waiting', 'destination', 'thank_you',
                'state_called', 'state_serving', 'start', 'start_hint', 'controls',
                'fullscreen', 'exit_fullscreen', 'sample_call',
            ]);
    }

    expect($promo['enabled'])->toBeTrue()
        // Clamped to the safe range.
        ->and($promo['slide_seconds'])->toBe(QueueDisplay::MIN_SLIDE_SECONDS)
        // The paused item is not published at all.
        ->and($promo['items'])->toHaveCount(2)
        ->and(array_keys($promo['items'][0]))->toBe(['kind', 'url', 'type', 'caption', 'alt'])
        ->and($promo['items'][0]['kind'])->toBe('image')
        ->and($promo['items'][0]['caption']['en'])->toBe('New season')
        ->and($promo['items'][1]['kind'])->toBe('video')
        ->and($promo['items'][1]['type'])->toBe('video/mp4');

    // The file is served by the center media route on the center's own host.
    $image = $this->get($promo['items'][0]['url'])->assertOk();
    expect($image->headers->get('Content-Type'))->toBe('image/png')
        ->and($image->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    // THE PRIVACY CHECK, blunt on purpose: the whole feed and the whole page.
    $body = (string) $on->getContent();
    $page = (string) $this->get(qdmPage($center, $display))->assertOk()->getContent();

    foreach ([$body, $page] as $surface) {
        expect($surface)->not->toContain('Sara Ahmed')
            ->and($surface)->not->toContain('9876543')
            ->and($surface)->not->toContain('customer')
            ->and($surface)->not->toContain('phone')
            ->and($surface)->not->toContain('"path"')
            ->and($surface)->not->toContain('size_bytes')
            ->and($surface)->not->toContain('branch_id');

        foreach ($uuids as $uuid) {
            expect(str_contains($surface, $uuid))->toBeFalse('An internal uuid reached a public surface.');
        }
    }

    // A screen that already holds this version gets the queue and a digest only.
    $version = $on->json('data.presentation_version');
    $again = $this->getJson(qdmFeed($center, $display, '?pv='.$version))->assertOk();

    expect($again->json('data.presentation_version'))->toBe($version)
        ->and($again->json('data'))->not->toHaveKey('presentation')
        ->and($again->json('data.now_calling.number'))->toBe($on->json('data.now_calling.number'));

    // An edit changes the digest, so the next poll carries it.
    $this->asCenter($center['tenant'], function () use ($display): void {
        $row = $display->promoMedia()->firstOrFail();
        app(ManageDisplayMedia::class)->update($row, ['caption' => ['en' => 'Last week of summer']], $this->ownerWithCatalogAccess());
    });

    $changed = $this->getJson(qdmFeed($center, $display, '?pv='.$version))->assertOk();
    expect($changed->json('data.presentation_version'))->not->toBe($version)
        ->and($changed->json('data.presentation.promo.items.0.caption.en'))->toBe('Last week of summer');

    $this->asCenter($center['tenant'], fn () => Storage::disk('public')->deleteDirectory('branding'));
});

it('never lets media or a language change touch a ticket, a call or an announcement', function (): void {
    $center = $this->registerCenter('Calm Center', 'owner@qdm-calm.test');

    [$display, $before] = $this->asCenter($center['tenant'], function (): array {
        $seed = qdmSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = app(SaveDisplay::class)([
            'branch' => $seed['branch']->uuid,
            'name' => 'Hall TV',
            'locale' => null,
            'voice_enabled' => true,
            'voice_locales' => ['ar', 'en'],
            'rotation_enabled' => true,
            'rotation_locales' => ['en', 'ar', 'ckb'],
        ], $owner);

        qdmCall($seed, $owner, 'First');
        qdmCall($seed, $owner, 'Second');

        return [$display, QueueTicket::query()->orderBy('id')->get(['id', 'state', 'last_announcement_uuid', 'last_called_at', 'call_count', 'updated_at'])->toArray()];
    });

    $first = $this->getJson(qdmFeed($center, $display, '?locale=en'))->assertOk();

    // Everything a screen might do to its presentation, all at once.
    $this->asCenter($center['tenant'], function () use ($display): void {
        $owner = $this->ownerWithCatalogAccess();
        $media = app(ManageDisplayMedia::class);

        $a = $media->add($display, UploadedFile::fake()->image('a.png', 640, 360), $owner);
        $media->add($display, UploadedFile::fake()->image('b.png', 640, 360), $owner);
        $media->configure($display, true, 6, $owner);
        $media->move($a, 1, $owner);
        $media->update($a, ['enabled' => false, 'caption' => ['ar' => 'نص']], $owner);
        $media->remove($a->fresh() ?? $a, $owner);
        app(SaveDisplay::class)(['rotation_seconds' => 20, 'rotation_locales' => ['ar', 'en']], $owner, $display);
    });

    // And the same screen read in another language.
    $second = $this->getJson(qdmFeed($center, $display, '?locale=ar'))->assertOk();

    // The queue half and the announcement are exactly what they were.
    expect($second->json('data.now_calling.announcement_id'))->toBe($first->json('data.now_calling.announcement_id'))
        ->and($second->json('data.now_calling.number'))->toBe($first->json('data.now_calling.number'))
        ->and($second->json('data.recent'))->toHaveCount(count($first->json('data.recent')))
        ->and($second->json('data.recent.0.announcement_id'))->toBe($first->json('data.recent.0.announcement_id'))
        // The announcement is keyed by the CALL: same id, same lines, in the
        // voice languages — whatever language the screen is showing.
        ->and($second->json('data.announcement'))->toBe($first->json('data.announcement'))
        ->and(array_keys($first->json('data.announcement.lines')))->toBe(['ar', 'en'])
        // While the presentation did change.
        ->and($second->json('data.presentation_version'))->not->toBe($first->json('data.presentation_version'))
        ->and($second->json('data.presentation.start'))->toBe('ar');

    $after = $this->asCenter($center['tenant'], fn (): array => QueueTicket::query()->orderBy('id')->get(['id', 'state', 'last_announcement_uuid', 'last_called_at', 'call_count', 'updated_at'])->toArray());

    expect($after)->toBe($before);

    $this->asCenter($center['tenant'], fn () => Storage::disk('public')->deleteDirectory('branding'));
});

it('renders the screen in its first language, with every rotation language in the page', function (): void {
    $center = $this->registerCenter('Page Center', 'owner@qdm-page.test');

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qdmSeed();

        $display = $this->seedDisplay($seed['branch'], 'Arabic TV', locale: 'ar');
        $display->forceFill(['rotation_enabled' => true, 'rotation_locales' => ['en', 'ar', 'ckb']])->save();

        return $display;
    });

    $html = (string) $this->get(qdmPage($center, $display))->assertOk()->getContent();

    expect($html)->toContain('<html lang="ar" dir="rtl">')
        // The panels keep their places while the language turns.
        ->and($html)->toContain('<main class="stage" dir="rtl">')
        ->and($html)->toContain(__('queue_public.now_calling', [], 'ar'))
        // The other languages ride in the page, for a switch with no request.
        ->and($html)->toContain(json_encode(__('queue_public.now_calling', [], 'ckb'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT))
        ->and($html)->toContain(json_encode(__('queue_public.now_calling', [], 'en'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT))
        ->and($html)->toContain('data-lang="ckb"')
        ->and($html)->toContain('>KU<')
        ->and($html)->toContain('QueueDisplayClient.mount')
        // Still read only.
        ->and($html)->not->toContain('<form')
        ->and($html)->not->toContain('csrf');

    // An English-first screen is LTR.
    $this->asCenter($center['tenant'], fn () => $display->forceFill(['locale' => 'en'])->save());

    $this->get(qdmPage($center, $display))->assertOk()->assertSee('<html lang="en" dir="ltr">', false);
});
