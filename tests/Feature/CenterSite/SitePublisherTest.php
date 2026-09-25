<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use App\Modules\CenterSite\Domain\Models\SiteVersion;
use App\Modules\CenterSite\Domain\SiteEditor;
use App\Modules\CenterSite\Domain\SiteVersionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Draft → publish → restore
|--------------------------------------------------------------------------
|
| One table, three states (like the menu). Editing never touches what is
| live; publishing archives the previous version; restoring copies an
| archived version FORWARD into the draft. Every write is authorised,
| validated against the center's own references, and audited.
|
*/

function siteAudit(string $action): int
{
    return DB::connection('tenant')->table('audit_logs')->where('action', $action)->count();
}

it('opens with the default page, saves a draft, publishes it and archives the previous version', function (): void {
    $center = $this->registerCenter('Site Center', 'owner@site.test');

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $publisher = app(SitePublisher::class);

        // Nothing stored yet: the builder opens on the default page and the
        // public page renders the same default.
        expect(SiteVersion::query()->count())->toBe(0);
        $content = $publisher->editableContent();
        expect($content['section_order'])->toContain('services', 'hours', 'booking_cta')
            ->and($content['hero']['title']['en'])->toBe('Site Center')
            ->and($content['sections']['about']['enabled'])->toBeFalse();

        $content['hero']['title'] = ['en' => 'Welcome to Site Center'];
        $draft = $publisher->saveDraft($content, $owner);

        expect($draft->status)->toBe(SiteVersionStatus::Draft)
            ->and($draft->version)->toBe(1)
            ->and($draft->saved_by_user_id)->toBe($owner->id)
            ->and($publisher->published())->toBeNull()
            ->and(siteAudit('center.site.draft_saved'))->toBe(1);

        $first = $publisher->publish($owner);
        expect($first->status)->toBe(SiteVersionStatus::Published)
            ->and($first->published_by_user_id)->toBe($owner->id)
            ->and($first->content['hero']['title'])->toBe(['en' => 'Welcome to Site Center'])
            ->and(siteAudit('center.site.published'))->toBe(1);

        // The next edit starts a NEW draft; the live version is untouched.
        $content = $publisher->editableContent();
        $content['hero']['title'] = ['en' => 'Second edition'];
        $second = $publisher->saveDraft($content, $owner);
        expect($second->version)->toBe(2)
            ->and($publisher->published()?->content['hero']['title'])->toBe(['en' => 'Welcome to Site Center']);

        $publisher->publish($owner);
        expect(SiteVersion::query()->published()->count())->toBe(1)
            ->and(SiteVersion::query()->archived()->pluck('version')->all())->toBe([1])
            ->and($publisher->published()?->version)->toBe(2);
    });
});

it('restores an archived version into the draft, never straight to the live page', function (): void {
    $center = $this->registerCenter('Restore Center', 'owner@restore.test');

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $publisher = app(SitePublisher::class);

        foreach (['First', 'Second'] as $title) {
            $content = $publisher->editableContent();
            $content['hero']['title'] = ['en' => $title];
            $publisher->publish($owner, $content);
        }
        $archived = SiteVersion::query()->archived()->firstOrFail();

        $draft = $publisher->restore($archived->uuid, $owner);

        expect($draft->status)->toBe(SiteVersionStatus::Draft)
            ->and($draft->version)->toBe(3)
            ->and($draft->restored_from_version)->toBe(1)
            ->and($draft->content['hero']['title'])->toBe(['en' => 'First'])
            // History is append-only: the archived row is unchanged, and the
            // live page still shows version 2 until the draft is published.
            ->and($archived->fresh()?->status)->toBe(SiteVersionStatus::Archived)
            ->and($publisher->published()?->content['hero']['title'])->toBe(['en' => 'Second'])
            ->and(siteAudit('center.site.version_restored'))->toBe(1);

        expect(fn () => $publisher->restore($draft->uuid, $owner))->toThrow(InvalidSiteContent::class);
    });
});

it('refuses to save, publish or restore without appearance.manage', function (): void {
    $center = $this->registerCenter('Guarded Center', 'owner@guarded.test');

    $this->asCenter($center['tenant'], function (): void {
        $viewer = $this->staffWith([Permission::AppearanceView], 'viewer@guarded.test');
        $publisher = app(SitePublisher::class);
        $content = $publisher->editableContent();

        expect(fn () => $publisher->saveDraft($content, $viewer))->toThrow(AuthorizationException::class)
            ->and(fn () => $publisher->publish($viewer, $content))->toThrow(AuthorizationException::class)
            ->and(SiteVersion::query()->count())->toBe(0);
    });
});

it('requires the center primary language when saving', function (): void {
    $center = $this->registerCenter('Arabic Center', 'owner@arabic.test');

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['ar', 'en'], 'ar');
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $publisher = app(SitePublisher::class);

        $content = $publisher->editableContent();
        [$content, $faq] = SiteEditor::addSection($content, 'faq');
        $content['sections'][$faq]['items'][0]['title'] = ['en' => 'Only English question'];
        $content['sections'][$faq]['items'][0]['body'] = ['en' => 'Only English answer'];

        try {
            $publisher->saveDraft($content, $owner);
            $this->fail('A question without Arabic should be refused.');
        } catch (InvalidSiteContent $e) {
            expect($e->reason)->toBe('primary_required')
                ->and($e->path)->toBe('sections.'.$faq.'.items.0.title.ar');
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
