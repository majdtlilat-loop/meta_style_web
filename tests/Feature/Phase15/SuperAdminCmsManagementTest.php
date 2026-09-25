<?php

declare(strict_types=1);

use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Livewire\Sadmin\Cms\Index as CmsIndex;
use App\Modules\LandingCms\Domain\Models\LandingPage;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->cmsUser = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->cmsUser->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($this->cmsUser, 'platform');
});

it('keeps drafts private then publishes the complete structured landing page', function (): void {
    $component = Livewire::test(CmsIndex::class)
        ->set('content.hero.title.en', 'Operate beautifully')
        ->set('content.hero.title.ar', 'إدارة أجمل')
        ->set('content.hero.title.ckb', 'بەڕێوەبردنێکی جوان')
        ->set('content.hero.body.en', 'One workspace for every operating day.')
        ->set('content.navigation.0.label.en', 'Product')
        ->set('content.navigation.0.label.ar', 'المنتج')
        ->set('content.navigation.0.label.ckb', 'بەرهەم')
        ->set('content.sections.features.items.0.title.en', 'Live operations')
        ->set('content.seo.title.en', 'Meta Style operations')
        ->set('content.seo.description.en', 'Run every customer-facing workflow in one workspace.')
        ->call('save', false)
        ->assertHasNoErrors();

    $page = LandingPage::query()->where('slug', 'home')->firstOrFail();
    expect($page->published_content)->toBeNull()
        ->and($page->draft_content['navigation'][0]['label']['ckb'])->toBe('بەرهەم')
        ->and(DB::connection('control')->table('landing_page_revisions')->where('landing_page_id', $page->id)->count())->toBe(1);

    $this->get('http://localhost:8000/?locale=en')
        ->assertOk()
        ->assertDontSee('Operate beautifully');

    $component->call('save', true)->assertHasNoErrors();
    $page->refresh();

    expect($page->status)->toBe('published')
        ->and($page->published_version)->toBe($page->draft_version)
        ->and(DB::connection('control')->table('landing_page_revisions')->where('landing_page_id', $page->id)->count())->toBe(2)
        ->and(DB::connection('control')->table('platform_audit_logs')->where('action', 'platform.cms.published')->where('target_id', $page->uuid)->exists())->toBeTrue();

    $this->get('http://localhost:8000/?locale=en')
        ->assertOk()
        ->assertSee('Operate beautifully')
        ->assertSee('Product')
        ->assertSee('Live operations')
        ->assertSee('<meta name="robots" content="index, follow">', false)
        ->assertSee('<meta name="description" content="Run every customer-facing workflow in one workspace.">', false);

    $this->get('http://localhost:8000/?locale=ar')->assertOk()->assertSee('إدارة أجمل')->assertSee('المنتج');
    $this->get('http://localhost:8000/?locale=ckb')->assertOk()->assertSee('بەڕێوەبردنێکی جوان')->assertSee('بەرهەم')->assertDontSee('>CKB<', false);
});

it('rejects executable content and unsafe links before creating a revision', function (): void {
    $component = Livewire::test(CmsIndex::class);
    $page = LandingPage::query()->where('slug', 'home')->firstOrFail();

    $component->set('content.hero.title.en', '<script>alert(1)</script>')
        ->call('save', false)
        ->assertHasErrors('content');

    expect(DB::connection('control')->table('landing_page_revisions')->where('landing_page_id', $page->id)->count())->toBe(0);

    $component->set('content.hero.title.en', 'Safe title')
        ->set('content.navigation.0.link_type', 'external')
        ->set('content.navigation.0.target', 'javascript:alert(1)')
        ->call('save', false)
        ->assertHasErrors('content');

    expect(DB::connection('control')->table('landing_page_revisions')->where('landing_page_id', $page->id)->count())->toBe(0);
});

it('accepts validated local CMS images and rejects SVG uploads', function (): void {
    Storage::fake('public');

    Livewire::test(CmsIndex::class)
        ->set('mediaTarget', 'hero.background_image')
        ->set('mediaUpload', UploadedFile::fake()->image('hero.jpg', 1200, 700))
        ->call('uploadMedia')
        ->assertHasNoErrors()
        ->assertSet('content.hero.background_image', fn (string $path): bool => str_starts_with($path, 'cms/landing/') && str_ends_with($path, '.jpg'));

    Livewire::test(CmsIndex::class)
        ->set('mediaTarget', 'hero.background_image')
        ->set('mediaUpload', UploadedFile::fake()->create('unsafe.svg', 8, 'image/svg+xml'))
        ->call('uploadMedia')
        ->assertHasErrors('mediaUpload');
});

it('keeps draft preview behind platform authentication, MFA and CMS permission', function (): void {
    auth('platform')->logout();
    $this->get('http://superadmin.localhost:8000/cms/preview')->assertRedirect(route('superadmin.login'));

    $this->actingAs($this->cmsUser, 'platform')
        ->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp])
        ->get('http://superadmin.localhost:8000/cms/preview')
        ->assertOk()
        ->assertSee(__('sadmin_cms.preview_mode'));
});
