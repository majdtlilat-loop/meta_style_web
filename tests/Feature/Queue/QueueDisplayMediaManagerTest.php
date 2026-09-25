<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Livewire\Center\Queue\DisplayMedia;
use App\Livewire\Center\Queue\Displays;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueDisplayMedia;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager: a screen's languages, its promotional media, and its preview
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §9. Configured where screens already are — the queue's
| "Desks & screens" tab. Every write goes through SaveDisplay /
| ManageDisplayMedia; the preview renders the real screen page and feed for a
| signed-in screen manager only.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('sets a screen to rotate through the center languages, and offers only those', function (): void {
    $center = $this->registerCenter('Rotation Desk', 'owner@qdm-desk.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $owner = $this->ownerWithCatalogAccess();

        $registry = app(LanguageRegistry::class);

        $screens = Livewire::actingAs($owner)->test(Displays::class)
            ->call('create')
            ->assertSet('rotationLocales', ['en', 'ar'])
            ->assertSee(__('manager_queue.setup.rotate_label'))
            ->set('languageRotation', true)
            // The language chips are on the page — and Kurdish, not switched
            // on for this center, is not among them.
            ->assertSee('AR · '.$registry->nativeName('ar'))
            ->assertDontSee('KU · '.$registry->nativeName('ckb'))
            ->set('name', 'Door TV')
            ->set('rotationLocales', ['en'])
            ->call('save')
            ->assertHasErrors(['rotationLocales']);

        $screens->set('rotationLocales', ['en', 'ar'])
            ->set('rotationSeconds', 12)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', '')
            ->assertSee('EN · AR');

        $display = QueueDisplay::query()->where('name', 'Door TV')->firstOrFail();

        expect($display->rotation_enabled)->toBeTrue()
            ->and($display->rotationLocales())->toBe(['en', 'ar'])
            ->and($display->rotation_seconds)->toBe(12)
            ->and($display->branch_id)->toBe($seed['branch']->id);

        // Out of the safe range is a validation message, not a clamp in silence.
        $screens->call('edit', $display->uuid)
            ->assertSet('languageRotation', true)
            ->set('rotationSeconds', 2)
            ->call('save')
            ->assertHasErrors(['rotationSeconds']);

        // Offered from the list: preview (with screens) and media.
        $screens->call('closePanel')
            ->assertSee(__('manager_queue.preview.open_for', ['name' => 'Door TV']))
            ->assertSee(__('manager_queue.media.open_for', ['name' => 'Door TV']));

        // A center with one language never sees rotation at all.
        app(TenantLocales::class)->setEnabled(['en'], 'en');
        Livewire::actingAs($owner)->test(Displays::class)
            ->call('edit', $display->uuid)
            ->assertDontSee(__('manager_queue.setup.rotate_label'));
    });
});

it('manages a screen\'s media from the Manager: upload, pause, order, caption, remove', function (): void {
    $center = $this->registerCenter('Media Desk', 'owner@qdm-mediadesk.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $owner = $this->ownerWithCatalogAccess();
        $display = $this->seedDisplay($seed['branch']);

        Livewire::actingAs($owner)->test(Displays::class)
            ->call('openMedia', $display->uuid)
            ->assertSet('mediaFor', $display->uuid)
            ->assertSeeLivewire(DisplayMedia::class)
            ->dispatch('display-media-closed')
            ->assertSet('mediaFor', '');

        $panel = Livewire::actingAs($owner)->test(DisplayMedia::class, ['display' => $display->uuid])
            ->assertSee(__('manager_queue.media.none'))
            ->set('files', [
                UploadedFile::fake()->image('one.png', 1280, 720),
                UploadedFile::fake()->image('two.png', 1280, 720),
            ])
            ->assertHasNoErrors()
            ->assertSet('notice', trans_choice('manager_queue.media.added', 2, ['count' => 2]))
            ->assertDispatched('display-media-changed');

        // A file that is not an image or a video is refused, in the manager's words.
        $panel->set('files', [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
            ->assertHasErrors(['files']);

        [$one, $two] = $display->promoMedia()->get()->all();

        $panel->set('enabled', true)
            ->set('slideSeconds', 12)
            ->call('toggleItem', $one->uuid)
            ->call('moveItemBy', $two->uuid, -1)
            ->call('editItem', $two->uuid)
            ->set('caption.en', 'Autumn colours')
            ->set('caption.ar', 'ألوان الخريف')
            ->set('alt.en', 'Two chairs')
            ->call('saveItem')
            ->assertHasNoErrors()
            ->assertSet('editing', '')
            ->assertSee('Autumn colours');

        $display->refresh();

        expect($display->promo_enabled)->toBeTrue()
            ->and($display->promo_slide_seconds)->toBe(12)
            ->and($display->promoMedia()->pluck('uuid')->all())->toBe([$two->uuid, $one->uuid])
            ->and($one->fresh()?->is_enabled)->toBeFalse()
            ->and($two->fresh()?->caption?->in('ar'))->toBe('ألوان الخريف')
            ->and($two->fresh()?->mediaItem?->alt_text?->in('en'))->toBe('Two chairs');

        // Too short to read is a validation message.
        $panel->set('slideSeconds', 1)->assertHasErrors(['slideSeconds']);

        // A caption longer than a line is refused.
        $panel->call('editItem', $one->uuid)
            ->set('caption.en', str_repeat('x', QueueDisplayMedia::CAPTION_MAX + 1))
            ->call('saveItem')
            ->assertHasErrors(['caption.en']);

        $panel->call('cancelItem')->call('removeItem', $one->uuid)
            ->assertSet('notice', __('manager_queue.media.removed'));

        expect($display->promoMedia()->pluck('uuid')->all())->toBe([$two->uuid]);

        // Another screen's item is not reachable from this panel.
        $other = $this->seedDisplay($seed['branch'], 'Other TV');
        $panel->call('removeItem', 'not-a-real-uuid')->assertSet('notice', __('manager_queue.errors.not_found'));
        Livewire::actingAs($owner)->test(DisplayMedia::class, ['display' => $other->uuid])
            ->call('removeItem', $two->uuid)
            ->assertSet('notice', __('manager_queue.errors.not_found'));

        expect(QueueDisplayMedia::query()->whereKey($two->id)->exists())->toBeTrue();

        Storage::disk('public')->deleteDirectory('branding');
    });
});

it('keeps the media panel to screen managers', function (): void {
    $center = $this->registerCenter('Media Rights', 'owner@qdm-rights.test');

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        $display = $this->seedDisplay($seed['branch']);

        $host = $this->staffWith([Permission::QueueView, Permission::QueueCall, Permission::MediaUpload], 'host@qdm-rights.test');

        Livewire::actingAs($host)->test(DisplayMedia::class, ['display' => $display->uuid])->assertForbidden();
    });
});

it('previews the real screen for a screen manager, never publicly and never speaking', function (): void {
    $center = $this->registerCenter('Preview Desk', 'owner@qdm-preview.test');
    $slug = $center['registration']->requested_slug;
    $owner = $this->ownerOf($center['tenant']);

    [$display, $host] = $this->asCenter($center['tenant'], function (): array {
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

        // A screen that speaks (voice on, `queue_voice` granted)...
        $display = $this->seedDisplay($seed['branch'], 'Hall TV');
        $display->forceFill(['rotation_enabled' => true, 'rotation_locales' => ['en', 'ar', 'ckb']])->save();

        // ...and somebody has just been called.
        $owner = $this->ownerWithCatalogAccess();
        $ticket = app(CreateWalkInTicket::class)(
            new WalkInRequest(
                branchUuid: $seed['branch']->uuid,
                serviceUuids: [$seed['service']->uuid],
                name: 'Sara',
                idempotencyToken: (string) Str::uuid(),
            ),
            $owner,
        )['ticket'];
        app(CallTicket::class)($ticket, $owner);

        return [$display, $this->staffWith([Permission::QueueView, Permission::QueueCall], 'host@qdm-preview.test')];
    });

    $page = "http://{$slug}.localhost:8000/manager/queue/displays/{$display->uuid}/preview";
    $feed = $page.'/feed';

    // The wall itself would speak and chime for this call: its public feed
    // carries the words and the new-call key.
    $public = $this->getJson("http://{$slug}.localhost:8000/api/v1/queue/{$slug}/displays/{$display->public_key}")->assertOk();
    expect($public->json('data.announcement.number'))->toBe('A001')
        ->and($public->json('data.call_key'))->toMatch('/^[0-9a-f]{16}$/');

    // Nobody signed in is sent to sign in.
    $this->get($page)->assertRedirect();

    $this->asCenter($center['tenant'], function () use ($center, $page, $feed, $owner, $host, $display, $slug): void {
        $this->actingAs($host);
        $this->get($page)->assertForbidden();
        $this->get($feed)->assertForbidden();

        $this->actingAs($owner);

        // The preview's feed shows the same call and carries no words and no
        // new-call key: nothing that could make it speak or chime.
        $preview = $this->get($feed)
            ->assertOk()
            ->assertJsonPath('data.now_calling.number', 'A001')
            ->assertJsonPath('data.presentation.rotation.locales', ['en', 'ar', 'ckb']);

        expect($preview->json('data.announcement'))->toBeNull()
            ->and($preview->json('data.call_key'))->toBeNull();

        // Switched off: still previewable, so it can be checked before it goes live.
        $display->forceFill(['is_active' => false])->save();

        $html = (string) $this->get($page.'?lang=ckb&sample=1')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->getContent();

        expect($html)->toContain('<html lang="ckb" dir="rtl">')
            ->and($html)->toContain('QueueDisplayClient.mount')
            ->and($html)->toContain('data-preview="true"')
            // No one-touch start: a preview never plays sound.
            ->and($html)->not->toContain('id="start-button"');

        $this->get($feed)->assertOk()->assertJsonPath('data.announcement', null);

        // Another center's key space: an unknown screen is simply not found.
        $this->get("http://{$slug}.localhost:8000/manager/queue/displays/00000000-0000-4000-8000-000000000000/preview")->assertNotFound();

        // Without screens in the plan there is nothing to preview.
        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'queue_display'],
            ['mode' => OverrideMode::Revoke],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        $this->get($page)->assertNotFound();
        expect($display->fresh()?->is_active)->toBeFalse();
    });

    // The public screen of a switched-off display still answers 404.
    $this->get("http://{$slug}.localhost:8000/q/{$display->public_key}")->assertNotFound();
});

it('previews a screen in every language the center publishes in, pinned and never rotating', function (): void {
    $center = $this->registerCenter('Preview Languages', 'owner@qdm-preview-lang.test');
    $slug = $center['registration']->requested_slug;
    $owner = $this->ownerOf($center['tenant']);

    [$door, $hall] = $this->asCenter($center['tenant'], function () use ($slug): array {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

        // English only: Arabic and Kurdish are not in anything it cycles.
        $door = $this->seedDisplay($seed['branch'], 'Door TV', locale: 'en');
        $hall = $this->seedDisplay($seed['branch'], 'Hall TV', locale: 'en');
        $hall->forceFill(['rotation_enabled' => true, 'rotation_locales' => ['en', 'ar']])->save();

        // The Manager offers every center language for either screen, labelled KU for Kurdish.
        $screens = collect(Livewire::actingAs($this->ownerWithCatalogAccess())->test(Displays::class)->viewData('displays'))->keyBy('uuid');
        $doorPreview = $screens[$door->uuid]['preview'];
        $hallPreview = $screens[$hall->uuid]['preview'];

        expect(array_column($doorPreview['languages'], 'code'))->toBe(['en', 'ar', 'ckb'])
            ->and(array_column($doorPreview['languages'], 'label'))->toBe(['EN', 'AR', 'KU'])
            ->and($doorPreview['rotates'])->toBeFalse()
            ->and($doorPreview['start'])->toBe('en')
            ->and(array_column($hallPreview['languages'], 'code'))->toBe(['en', 'ar', 'ckb'])
            ->and($hallPreview['rotates'])->toBeTrue();

        return [$door, $hall];
    });

    $page = fn (QueueDisplay $display, string $query = ''): string => "http://{$slug}.localhost:8000/manager/queue/displays/{$display->uuid}/preview{$query}";
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

    $this->asCenter($center['tenant'], function () use ($owner, $door, $hall, $page, $flags): void {
        $this->actingAs($owner);

        // Kurdish, which this screen never shows by itself: its texts and RTL.
        $kurdish = (string) $this->get($page($door, '?lang=ckb'))->assertOk()->getContent();

        expect($kurdish)->toContain('<html lang="ckb" dir="rtl">')
            ->and($kurdish)->toContain('<main class="stage" dir="rtl">')
            ->and($kurdish)->toContain(e(__('queue_public.now_calling', [], 'ckb')))
            ->and($kurdish)->toContain('"lockLocale":"ckb"')
            // Its feed is pinned too, so a poll never hands the screen back.
            ->and($kurdish)->toContain('feed?lang=ckb')
            // One language only: nothing to rotate to.
            ->and($kurdish)->not->toContain(json_encode(__('queue_public.now_calling', [], 'en'), $flags));

        $this->get($page($door, '/feed?lang=ckb'))
            ->assertOk()
            ->assertJsonPath('data.display.locale', 'ckb')
            ->assertJsonPath('data.presentation.start', 'ckb')
            ->assertJsonPath('data.presentation.rotation.enabled', false)
            ->assertJsonPath('data.presentation.rotation.locales', ['ckb'])
            ->assertJsonPath('data.presentation.languages.ckb.dir', 'rtl')
            ->assertJsonPath('data.presentation.languages.ckb.label', 'KU')
            ->assertJsonPath('data.presentation.languages.ckb.text.now_calling', __('queue_public.now_calling', [], 'ckb'));

        // Arabic is RTL, English LTR.
        $this->get($page($door, '?lang=ar'))->assertOk()->assertSee('<html lang="ar" dir="rtl">', false);
        $this->get($page($door, '?lang=en'))->assertOk()->assertSee('<html lang="en" dir="ltr">', false);

        // A rotating screen pinned to one of its languages holds still.
        $this->get($page($hall, '/feed?lang=ar'))
            ->assertOk()
            ->assertJsonPath('data.presentation.start', 'ar')
            ->assertJsonPath('data.presentation.rotation.enabled', false);

        // A language the center has switched off is not accepted.
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');

        $this->get($page($door, '?lang=ckb'))->assertOk()->assertSee('<html lang="en" dir="ltr">', false);

        $refused = $this->get($page($door, '/feed?lang=ckb'))->assertOk();

        expect($refused->json('data.presentation.start'))->toBe('en')
            ->and($refused->json('data.presentation.languages'))->not->toHaveKey('ckb');
    });
});
