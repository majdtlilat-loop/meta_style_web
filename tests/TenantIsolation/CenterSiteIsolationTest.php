<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Kernel\Media\Models\MediaItem;
use App\Modules\CenterSite\Application\Actions\UpdateCenterBrand;
use App\Modules\CenterSite\Application\Actions\UploadSiteMedia;
use App\Modules\CenterSite\Application\BrandSettings;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use App\Modules\CenterSite\Domain\Models\SiteVersion;
use App\Modules\CenterSite\Domain\SiteContent;
use App\Modules\CenterSite\Domain\SiteEditor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Center site isolation
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §4 — release gate.
|
| A center's site versions, brand and site media live in its own database.
| A uuid copied from another center — a media item, an employee — is not in
| this center's references, so the normalizer refuses it; and one center's
| public page never shows another's content.
|
*/

it('keeps site versions and brands per center', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $publisher = app(SitePublisher::class);
        $content = $publisher->editableContent();
        $content['hero']['title'] = ['en' => 'Alpha only headline'];
        $publisher->publish($owner, $content);
        app(UpdateCenterBrand::class)(['light' => ['primary' => '#112233']], $owner);
    });

    $inBeta = $this->asCenter($beta['tenant'], fn (): array => [
        'versions' => SiteVersion::query()->count(),
        'primary' => app(BrandSettings::class)->get()['light']['primary'],
    ]);

    expect($inBeta['versions'])->toBe(0)
        ->and($inBeta['primary'])->not->toBe('#112233');

    $betaSlug = $beta['registration']->requested_slug;
    $alphaSlug = $alpha['registration']->requested_slug;
    $this->get("http://{$betaSlug}.localhost:8000/")->assertOk()->assertDontSee('Alpha only headline');
    $this->get("http://{$alphaSlug}.localhost:8000/")->assertOk()->assertSee('Alpha only headline');
});

it('refuses another center\'s media uuid and employee uuid in the site content', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    [$alphaMedia, $alphaEmployee] = $this->asCenter($alpha['tenant'], function (): array {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $media = app(UploadSiteMedia::class)(UploadedFile::fake()->image('alpha.png', 800, 600), 'image', $owner);

        $uuid = $media->uuid;
        // The row is what matters below; the file is not needed.
        Storage::disk('public')->deleteDirectory('branding');

        return [$uuid, $this->seedEmployee('Alpha Stylist')->uuid];
    });

    $this->asCenter($beta['tenant'], function () use ($alphaMedia, $alphaEmployee): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $publisher = app(SitePublisher::class);

        expect(MediaItem::query()->where('uuid', $alphaMedia)->exists())->toBeFalse();

        $content = $publisher->editableContent();
        $content['hero']['image'] = $alphaMedia;
        try {
            $publisher->saveDraft($content, $owner);
            $this->fail('Another center\'s media must be refused.');
        } catch (InvalidSiteContent $e) {
            expect($e->reason)->toBe('media');
        }

        $content = $publisher->editableContent();
        [$content, $team] = SiteEditor::addSection($content, 'team');
        $member = SiteContent::blankItem('team');
        $member['employee_uuid'] = $alphaEmployee;
        $content['sections'][$team]['items'] = [$member];
        try {
            $publisher->saveDraft($content, $owner);
            $this->fail('Another center\'s employee must be refused.');
        } catch (InvalidSiteContent $e) {
            expect($e->reason)->toBe('reference');
        }

        expect(SiteVersion::query()->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
