<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Livewire\Center\Appearance\Brand;
use App\Livewire\Center\Appearance\Site;
use App\Livewire\Center\Appearance\SiteHistory;
use App\Livewire\Center\Appearance\SiteMediaSlot;
use App\Modules\CenterSite\Application\Actions\UploadSiteMedia;
use App\Modules\CenterSite\Application\BrandSettings;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\CenterSite\Domain\Models\SiteVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager → Appearance: the landing page builder and the brand page
|--------------------------------------------------------------------------
|
| The component orchestrates; SiteEditor does the structure, SiteContent
| validates, SitePublisher saves and audits. These tests drive the component
| the way the page does and check the stored result.
|
*/

it('builds a page: add, duplicate, toggle, reorder, remove sections and menu items, then publish', function (): void {
    $center = $this->registerCenter('Builder Center', 'owner@builder.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $component = Livewire::actingAs($owner)->test(Site::class)
            ->assertOk()
            ->assertSee(__('manager_site.title'))
            ->call('setPanel', 'section:services')
            ->call('addSection', 'faq')
            ->assertSet('panel', 'section:faq');

        // Added right after the section being edited.
        $order = $component->get('content.section_order');
        expect(array_slice($order, array_search('services', $order, true), 2))->toBe(['services', 'faq']);

        $component->set('content.sections.faq.title.en', 'Questions')
            ->set('content.sections.faq.items.0.title.en', 'Do you take walk-ins?')
            ->set('content.sections.faq.items.0.body.en', 'Yes, whenever a chair is free.')
            ->call('duplicateSection', 'faq')
            ->assertSet('panel', 'section:faq_2')
            ->call('toggleSection', 'faq_2')
            ->assertSet('content.sections.faq_2.enabled', false)
            ->call('sortSections', 'faq_2', 0)
            ->call('moveSection', 'faq_2', 1)
            ->call('askRemoveSection', 'faq_2')
            ->assertSet('removingSection', 'faq_2')
            ->call('removeSection')
            ->assertSet('removingSection', null);
        expect($component->get('content.sections'))->not->toHaveKey('faq_2');

        $component->call('addItem', 'navigation')
            ->set('content.navigation.3.label.en', 'Questions')
            ->set('content.navigation.3.link_type', 'section')
            ->set('content.navigation.3.target', 'faq');
        $navigation = $component->get('content.navigation');
        $component->call('sortItems', 'navigation|'.$navigation[3]['id'], 0);
        expect($component->get('content.navigation.0.label.en'))->toBe('Questions');

        $component->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('unsaved', false)
            ->assertDispatched('site-saved');
        expect(SiteVersion::query()->draft()->firstOrFail()->content['sections']['faq']['items'][0]['title'])->toBe(['en' => 'Do you take walk-ins?']);

        $component->set('confirmingPublish', true)->call('publish')->assertHasNoErrors();
        expect(app(SitePublisher::class)->published()?->content['navigation'][0]['target'])->toBe('faq');
    });
});

it('maps a refusal to the field and opens the panel that holds it', function (): void {
    $center = $this->registerCenter('Refusal Center', 'owner@refusal.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)->test(Site::class)
            ->call('setPanel', 'seo')
            ->set('content.hero.title.en', '<script>alert(1)</script>')
            ->call('saveDraft')
            ->assertHasErrors(['content', 'content.hero.title.en'])
            ->assertSet('panel', 'hero');

        Livewire::actingAs($owner)->test(Site::class)
            ->call('addItem', 'footer.social')
            ->set('content.footer.social.0.url', 'javascript:alert(1)')
            ->call('saveDraft')
            ->assertHasErrors(['content.footer.social.0.url'])
            ->assertSet('panel', 'footer');

        // The footer numbers use the one phone field: a number that does not
        // fit its country is refused on that field...
        Livewire::actingAs($owner)->test(Site::class)
            ->set('phones.phone.country', 'IQ')
            ->set('phones.phone.number', '12')
            ->call('saveDraft')
            ->assertHasErrors(['content.footer.contact_phone', 'phones.phone.number'])
            ->assertSet('panel', 'footer');

        expect(SiteVersion::query()->count())->toBe(0);

        // ...and a valid one is stored as E.164 and shown back as country + number.
        Livewire::actingAs($owner)->test(Site::class)
            ->set('phones.phone.country', 'IQ')
            ->set('phones.phone.number', '0750 123 4567')
            ->set('phones.whatsapp.number', '')
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSet('content.footer.contact_phone', '+9647501234567')
            ->assertSet('phones.phone.number', '7501234567')
            ->assertSet('phones.phone.country', 'IQ');

        expect(app(SitePublisher::class)->currentDraft()?->content['footer']['contact_phone'])->toBe('+9647501234567');
    });
});

it('lets a viewer open the builder but not change it', function (): void {
    $center = $this->registerCenter('Viewer Center', 'owner@viewer.test');

    $this->asCenter($center['tenant'], function (): void {
        $viewer = $this->staffWith([Permission::AppearanceView], 'viewer@viewer.test');
        $nobody = $this->staffWith([Permission::CustomerView], 'nobody@viewer.test');

        Livewire::actingAs($viewer)->test(Site::class)
            ->assertOk()
            ->assertSee(__('manager_site.read_only'))
            ->call('addSection', 'faq')
            ->assertForbidden();

        Livewire::actingAs($nobody)->test(Site::class)->assertForbidden();
        Livewire::actingAs($nobody)->test(Brand::class)->assertForbidden();
    });
});

it('uploads site media into a slot, and a video only when it really is one', function (): void {
    $center = $this->registerCenter('Media Center', 'owner@media.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $upload = app(UploadSiteMedia::class);

        $image = $upload(UploadedFile::fake()->image('hero.jpg', 1600, 900), 'image', $owner);
        $mp4 = UploadedFile::fake()->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
        $video = $upload($mp4, 'video', $owner);

        expect($image->owner_type)->toBe(MediaOwner::Site)
            ->and($video->mime_type)->toBe('video/mp4')
            ->and($video->path)->toEndWith('.mp4');

        expect(fn () => $upload(UploadedFile::fake()->createWithContent('clip.mp4', '<html><script>alert(1)</script></html>'), 'video', $owner))->toThrow(ValidationException::class)
            ->and(fn () => $upload(UploadedFile::fake()->createWithContent('art.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'), 'image', $owner))->toThrow(ValidationException::class);

        $viewer = $this->staffWith([Permission::AppearanceView, Permission::MediaUpload], 'viewer@media.test');
        expect(fn () => $upload(UploadedFile::fake()->image('x.png', 400, 300), 'image', $viewer))->toThrow(AuthorizationException::class);

        // The slot component uploads and hands the uuid to the builder.
        Livewire::actingAs($owner)->test(SiteMediaSlot::class, ['target' => 'hero.image', 'kind' => 'image', 'canManage' => true])
            ->set('file', UploadedFile::fake()->image('new-hero.png', 1200, 800))
            ->assertDispatched('site-media-selected', target: 'hero.image');

        $uuid = MediaItem::query()->where('owner_type', MediaOwner::Site->value)->latest('id')->value('uuid');

        Livewire::actingAs($owner)->test(Site::class)
            ->call('mediaSelected', 'hero.image', $uuid)
            ->assertSet('content.hero.image', $uuid)
            ->call('mediaSelected', 'seo.title', $uuid)
            ->assertSet('content.seo.title', app(SitePublisher::class)->editableContent()['seo']['title'])
            ->call('clearMedia', 'hero.image')
            ->assertSet('content.hero.image', '')
            ->call('mediaSelected', 'hero.image', $uuid)
            ->call('saveDraft')
            ->assertHasNoErrors();

        // Removing from a slot only unreferences: the file stays.
        expect(MediaItem::query()->where('uuid', $uuid)->exists())->toBeTrue();

        // Only this center's files: other suites may be using the storage root.
        Storage::disk('public')->deleteDirectory('branding');
    });
});

it('restores a version from the history panel into the draft', function (): void {
    $center = $this->registerCenter('History Center', 'owner@history.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $publisher = app(SitePublisher::class);
        foreach (['One', 'Two'] as $title) {
            $content = $publisher->editableContent();
            $content['hero']['title'] = ['en' => $title];
            $publisher->publish($owner, $content);
        }
        $archived = SiteVersion::query()->archived()->firstOrFail();

        Livewire::actingAs($owner)->test(SiteHistory::class)
            ->assertSee(__('manager_site.history.version', ['version' => 1]))
            ->call('restore', $archived->uuid)
            ->assertDispatched('site-restored');

        expect($publisher->currentDraft()?->content['hero']['title'])->toBe(['en' => 'One']);
    });
});

it('edits the brand with a live preview and saves it', function (): void {
    $center = $this->registerCenter('Brand Page Center', 'owner@brandpage.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)->test(Brand::class)
            ->assertOk()
            ->set('brand.light.primary', '#123ABC')
            ->assertSee('--center-primary:#123abc', false)
            ->assertSee(__('manager_site.brand.unsaved'))
            ->set('brand.light.text', 'not-a-colour')
            ->assertSee(__('manager_site.errors.color'))
            ->set('brand.light.text', '#231f20')
            ->set('brand.radius', 'pill')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(BrandSettings::class)->get()['light']['primary'])->toBe('#123abc')
            ->and(app(BrandSettings::class)->get()['radius'])->toBe('pill');

        Livewire::actingAs($owner)->test(Brand::class)
            ->set('logoLight', UploadedFile::fake()->image('logo.png', 400, 120))
            ->assertHasNoErrors()
            ->call('askReset')
            ->assertSet('confirm', 'reset')
            ->call('confirmAction')
            ->assertSet('confirm', null);

        $brand = app(BrandSettings::class)->get();
        expect($brand['light']['primary'])->not->toBe('#123abc')
            ->and($brand['logo_light'])->not->toBe('');

        // Only this center's files: other suites may be using the storage root.
        Storage::disk('public')->deleteDirectory('branding');
    });
});

it('serves the builder and brand pages on the center host', function (): void {
    $center = $this->registerCenter('Pages Center', 'owner@pages.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);

        foreach (['en' => 'ltr', 'ar' => 'rtl', 'ckb' => 'rtl'] as $locale => $direction) {
            $html = $this->get("http://{$slug}.localhost:8000/manager/appearance/site?locale={$locale}")
                ->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->getContent();
            expect(preg_match('/\bmanager_site\.[a-z_]+/', strip_tags((string) $html)))->toBe(0);

            $this->get("http://{$slug}.localhost:8000/manager/appearance/brand?locale={$locale}")
                ->assertOk()
                ->assertSee(__('manager_site.brand.title', [], $locale));
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
