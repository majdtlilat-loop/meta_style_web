<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\CenterSite\Application\Actions\ManageBrandAssets;
use App\Modules\CenterSite\Application\Actions\UpdateCenterBrand;
use App\Modules\CenterSite\Application\Actions\UploadSiteMedia;
use App\Modules\CenterSite\Application\SitePublisher;
use App\Modules\CenterSite\Domain\SiteContent;
use App\Modules\CenterSite\Domain\SiteEditor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The center's public landing page
|--------------------------------------------------------------------------
|
| `/` on the center host renders the PUBLISHED site end to end — header,
| hero, sections in the configured order (disabled ones skipped), footer,
| SEO/robots/Open Graph, brand tokens, favicon — in every enabled content
| language, right to left where the language is. Data sections read the live
| catalog and branches; nothing internal reaches the page.
|
*/

/**
 * Publishes a page with the given edits applied to the current content.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $edit
 */
function publishSite(callable $edit): void
{
    $owner = User::query()->where('is_owner', true)->firstOrFail();
    $publisher = app(SitePublisher::class);
    $publisher->publish($owner, $edit($publisher->editableContent()));
}

it('renders the default page for a center that has never published', function (): void {
    $center = $this->registerCenter('Fresh Center', 'owner@fresh.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function (): void {
        $this->seedCatalog();
    });

    $html = (string) $this->get("http://{$slug}.localhost:8000/")
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false)
        ->assertSee('<title>Fresh Center</title>', false)
        ->assertSee('Haircut')
        ->assertSee('20,000 IQD')
        ->assertSee('name="robots" content="index, follow"', false)
        ->getContent();

    // The disabled About section and a rating with no reviews do not render,
    // and nothing is a placeholder.
    expect(str_contains($html, 'id="about"'))->toBeFalse()
        ->and(str_contains($html, 'id="rating"'))->toBeFalse()
        ->and(str_contains($html, 'center_site.'))->toBeFalse();
});

it('renders the published sections in order, skips disabled ones, and carries SEO and brand', function (): void {
    $center = $this->registerCenter('Order Center', 'owner@order.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function (): void {
        $this->seedCatalog();
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        app(UpdateCenterBrand::class)(['light' => ['primary' => '#0f766e']], $owner);

        publishSite(function (array $content): array {
            [$content, $faq] = SiteEditor::addSection($content, 'faq');
            $content['sections'][$faq]['title'] = ['en' => 'Questions we hear'];
            $content['sections'][$faq]['items'][0]['title'] = ['en' => 'Do you take walk-ins?'];
            $content['sections'][$faq]['items'][0]['body'] = ['en' => 'Yes, whenever a chair is free.'];
            [$content, $text] = SiteEditor::addSection($content, 'text_media');
            $content['sections'][$text]['title'] = ['en' => 'A hidden story'];
            $content['sections'][$text]['enabled'] = false;
            // FAQ first, then services.
            $content = SiteEditor::placeSection($content, $faq, 0);
            $content['seo']['title'] = ['en' => 'Order Center — Hair and beauty'];
            $content['seo']['description'] = ['en' => 'Book hair and beauty services online.'];
            $content['seo']['index'] = false;

            return $content;
        });
    });

    $html = (string) $this->get("http://{$slug}.localhost:8000/")
        ->assertOk()
        ->assertSee('<title>Order Center — Hair and beauty</title>', false)
        ->assertSee('<meta name="description" content="Book hair and beauty services online.">', false)
        ->assertSee('<meta name="robots" content="noindex, follow">', false)
        ->assertSee('<meta property="og:title" content="Order Center — Hair and beauty">', false)
        ->assertSee('--center-primary:#0f766e;', false)
        ->assertSee('Do you take walk-ins?')
        ->assertDontSee('A hidden story')
        ->getContent();

    expect(strpos($html, 'id="faq"'))->toBeLessThan((int) strpos($html, 'id="services"'))
        ->and(str_contains($html, '<link rel="canonical" href="http://'.$slug.'.localhost:8000">'))->toBeTrue();
});

it('speaks every enabled content language, right to left for Arabic and Kurdish', function (string $locale, string $direction, string $title): void {
    $center = $this->registerCenter('Lingua Center', 'owner@lingua.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');
        publishSite(function (array $content): array {
            $content['hero']['title'] = ['en' => 'Welcome', 'ar' => 'أهلًا بكم', 'ckb' => 'بەخێربێن'];

            return $content;
        });
    });

    $html = (string) $this->get("http://{$slug}.localhost:8000/?locale={$locale}")
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
        ->assertSee($title)
        ->assertSee('hreflang="ckb"', false)
        ->assertSee('hreflang="x-default"', false)
        ->getContent();

    // The language switch shows KU, never CKB.
    expect(str_contains($html, '>KU</a>'))->toBeTrue()
        ->and(str_contains($html, '>CKB<'))->toBeFalse();
})->with([
    'English' => ['en', 'ltr', 'Welcome'],
    'Arabic' => ['ar', 'rtl', 'أهلًا بكم'],
    'Kurdish Sorani' => ['ckb', 'rtl', 'بەخێربێن'],
]);

it('falls back to the primary language when a translation is missing', function (): void {
    $center = $this->registerCenter('Fallback Center', 'owner@fallback.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['ar', 'en'], 'ar');
        publishSite(function (array $content): array {
            $content['hero']['title'] = ['ar' => 'مرحبًا بكم في المركز'];

            return $content;
        });
    });

    $this->get("http://{$slug}.localhost:8000/?locale=en")
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr"', false)
        ->assertSee('مرحبًا بكم في المركز');
});

it('shows only public data, only the chosen team, and no contact the center did not publish', function (): void {
    $center = $this->registerCenter('Private Center', 'owner@private.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();
        $catalog['service']->addInternalNote('Cost 8,000 — never quote below 15,000.');
        Service::query()->create([
            'name' => TranslatedText::make('en', 'Staff Only Service'),
            'duration_minutes' => 30, 'price_minor' => 1000, 'is_active' => true, 'is_public' => false,
        ]);
        $chosen = $this->seedEmployee('Layla Chosen');
        $this->seedEmployee('Omar Unchosen');
        Branch::query()->where('is_main', true)->update(['phone' => '+9647700000001', 'is_public' => true]);

        publishSite(function (array $content) use ($chosen): array {
            [$content, $team] = SiteEditor::addSection($content, 'team');
            $content['sections'][$team]['title'] = ['en' => 'Meet the team'];
            $member = SiteContent::blankItem('team');
            $member['employee_uuid'] = $chosen->uuid;
            $member['title'] = ['en' => 'Senior stylist'];
            $content['sections'][$team]['items'] = [$member];

            return $content;
        });
    });

    $html = (string) $this->get("http://{$slug}.localhost:8000/")->assertOk()->getContent();

    expect($html)->toContain('Layla Chosen')
        ->toContain('Senior stylist')
        ->toContain('tel:+9647700000001');
    expect(str_contains($html, 'Omar Unchosen'))->toBeFalse()
        ->and(str_contains($html, 'Staff Only Service'))->toBeFalse()
        ->and(str_contains($html, 'Cost 8,000'))->toBeFalse()
        ->and(str_contains($html, 'owner@private.test'))->toBeFalse();
});

it('serves site media, the gallery, social links, the favicon and the sharing image from the center host', function (): void {
    $center = $this->registerCenter('Gallery Center', 'owner@gallery.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function (): void {
        $owner = User::query()->where('is_owner', true)->firstOrFail();
        $upload = app(UploadSiteMedia::class);
        $photo = $upload(UploadedFile::fake()->image('work.jpg', 1200, 900), 'image', $owner)->uuid;
        $share = $upload(UploadedFile::fake()->image('share.png', 1200, 630), 'image', $owner)->uuid;
        app(ManageBrandAssets::class)->upload('favicon', UploadedFile::fake()->image('icon.png', 64, 64), $owner);

        publishSite(function (array $content) use ($photo, $share): array {
            [$content, $gallery] = SiteEditor::addSection($content, 'gallery');
            $content['sections'][$gallery]['title'] = ['en' => 'Our work'];
            $item = SiteContent::blankItem('gallery');
            $item['image'] = $photo;
            $item['title'] = ['en' => 'Balayage by Layla'];
            $item['image_alt'] = ['en' => 'Warm balayage on long hair'];
            $content['sections'][$gallery]['items'] = [$item];
            $content['seo']['og_image'] = $share;
            $content['footer']['social'] = [['id' => 'soc12345', 'enabled' => true, 'network' => 'instagram', 'url' => 'https://www.instagram.com/gallerycenter']];

            return $content;
        });
    });

    $html = (string) $this->get("http://{$slug}.localhost:8000/")
        ->assertOk()
        ->assertSee('Balayage by Layla')
        ->assertSee('alt="Warm balayage on long hair"', false)
        ->assertSee('href="https://www.instagram.com/gallerycenter"', false)
        ->assertSee('rel="icon"', false)
        ->assertSee('<meta property="og:image" content="http://'.$slug.'.localhost:8000/media/branding/', false)
        ->getContent();

    preg_match('#http://[^"]+/media/(branding/[0-9a-f-]{36}\.(?:jpg|png))#', $html, $match);
    expect($match)->not->toBe([]);

    $this->get("http://{$slug}.localhost:8000/media/".$match[1])
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->asCenter($center['tenant'], fn () => Storage::disk('public')->deleteDirectory('branding'));
});

it('renders the page in a bounded number of queries however large the catalog', function (): void {
    $center = $this->registerCenter('Busy Center', 'owner@busy.test');
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();
        for ($i = 1; $i <= 30; $i++) {
            Service::query()->create([
                'service_category_id' => $catalog['category']->id,
                'name' => TranslatedText::make('en', 'Extra service '.$i),
                'duration_minutes' => 30, 'price_minor' => 1000 * $i,
                'is_active' => true, 'is_public' => true, 'available_at_all_branches' => true, 'sort_order' => $i,
            ]);
        }
        publishSite(function (array $content): array {
            $content['sections']['services']['source']['limit'] = 24;

            return $content;
        });
    });

    $queries = 0;
    Event::listen(function (QueryExecuted $event) use (&$queries): void {
        if ($event->connectionName === 'tenant') {
            $queries++;
        }
    });

    $this->get("http://{$slug}.localhost:8000/")->assertOk()->assertSee('Extra service 23')->assertDontSee('Extra service 24');

    // Bounded by the number of RELATIONS the page reads, not by the number of
    // services (9 at the time of writing). A jump here is a new N+1.
    expect($queries)->toBeGreaterThan(0)->toBeLessThan(20);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
