<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\CenterSite\Application\SitePublisher;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
|--------------------------------------------------------------------------
| The draft preview
|--------------------------------------------------------------------------
|
| The builder's preview frame renders the SAVED DRAFT. It lives in the
| authenticated Manager group — never behind the public resolver (ADR-036) —
| needs appearance.view, is never indexed or cached, and its `lang` choice
| never changes the staff member's own interface language.
|
*/

it('keeps the preview behind staff sign-in and off the public resolver', function (): void {
    /** @var Route $route */
    $route = Router::getRoutes()->getByName('center.appearance.site.preview');
    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain('auth:web')
        ->toContain('tenant')
        ->and(in_array('public.tenant', $middleware, true))->toBeFalse();

    foreach (['center.appearance.site', 'center.appearance.brand'] as $name) {
        expect(Router::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('auth:web');
    }
});

it('shows the saved draft to appearance staff only, never indexed', function (): void {
    $center = $this->registerCenter('Preview Center', 'owner@preview.test');
    $slug = $center['registration']->requested_slug;
    $owner = $this->ownerOf($center['tenant']);

    [$viewer, $cashier] = $this->asCenter($center['tenant'], function (): array {
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $publisher = app(SitePublisher::class);

        $content = $publisher->editableContent();
        $content['hero']['title'] = ['en' => 'Live title', 'ar' => 'العنوان المنشور'];
        $publisher->publish($owner, $content);

        $content = $publisher->editableContent();
        $content['hero']['title'] = ['en' => 'Draft title', 'ar' => 'عنوان المسودة'];
        $publisher->saveDraft($content, $owner);

        return [
            $this->staffWith([Permission::AppearanceView], 'viewer@preview.test'),
            $this->staffWith([Permission::CustomerView], 'cashier@preview.test'),
        ];
    });

    $url = "http://{$slug}.localhost:8000/manager/appearance/site/preview";

    // A guest is sent to sign in.
    $this->get($url)->assertRedirect();

    // The public page still shows what is live.
    $this->get("http://{$slug}.localhost:8000/")->assertOk()->assertSee('Live title')->assertDontSee('Draft title');

    $this->asCenter($center['tenant'], function () use ($url, $slug, $owner, $viewer, $cashier): void {
        $this->actingAs($cashier);
        $this->get($url)->assertForbidden();

        $this->actingAs($viewer);
        $this->get($url)->assertOk()->assertSee('Draft title');

        $this->actingAs($owner);
        $this->get($url.'?lang=ar')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('<html lang="ar" dir="rtl"', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('عنوان المسودة')
            ->assertSee(__('center_site.preview.title', [], 'ar'));

        // A language the center has not enabled falls back to its primary.
        $this->get($url.'?lang=ckb')->assertOk()->assertSee('<html lang="en" dir="ltr"', false);

        // Previewing Arabic did not switch the Manager to Arabic.
        $this->get("http://{$slug}.localhost:8000/manager/appearance/site")
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr"', false);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
