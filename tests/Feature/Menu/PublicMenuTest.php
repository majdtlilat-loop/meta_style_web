<?php

declare(strict_types=1);

use App\Kernel\Localization\TranslatedText;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\PlatformHosts;
use App\Kernel\Tenancy\Tenant;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Menu\Application\MenuPublisher;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Public electronic menu
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 4 §§12–13 · docs/DECISIONS.md ADR-036.
|
| The one page in the product a stranger opens. So it has to be reachable
| without an account, show only what a center chose to publish, and leak
| nothing operational — and it has to do that in a bounded number of queries,
| because it renders a whole catalog at once.
|
| Since Phase 15 (ADR-076) a guest reaches it on the center's own subdomain,
| with the same public slug in the path: the HOST resolves the center, and the
| path slug only names the resource — a slug that disagrees with the host is
| "no center here", never a second resolution source.
|
*/

/**
 * Publishes a menu and a catalog inside the given center.
 *
 * Goes through Pest's `test()` proxy rather than taking the case as an
 * argument: the trait methods are protected, and a file-scope function is not
 * bound to the test class.
 *
 * @param  array{tenant: Tenant, registration: Registration, password: string, access_token: string}  $center
 */
function seedPublishedMenu(array $center): void
{
    test()->asCenter($center['tenant'], function (): void {
        test()->publishMenu();
        test()->seedCatalog();
    });
}

/**
 * The public menu API URL for a center: its own subdomain, its slug in the path.
 *
 * @param  array{tenant: Tenant, registration: Registration, password: string, access_token: string}  $center
 */
function pmUrl(array $center, string $query = ''): string
{
    $slug = (string) $center['registration']->requested_slug;

    return app(PlatformHosts::class)->centerUrl($slug, '/api/v1/menu/'.$slug.$query);
}

it('serves a center menu to a guest with no credentials at all', function (): void {
    $center = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    seedPublishedMenu($center);

    $response = $this->getJson(pmUrl($center))->assertOk();

    expect($response->json('data.center.name'))->toBe('Barbershop Alpha')
        ->and($response->json('data.services'))->toHaveCount(1)
        ->and($response->json('data.services.0.name'))->toBe('Haircut')
        ->and($response->json('data.services.0.price.amount'))->toBe(20000)
        ->and($response->json('data.categories'))->toHaveCount(1);
});

it('serves each center its own menu and never another\'s', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    seedPublishedMenu($alpha);

    $this->asCenter($beta['tenant'], function (): void {
        $this->publishMenu();

        Service::query()->create([
            'name' => TranslatedText::make('en', 'Beta Only Treatment'),
            'duration_minutes' => 60,
            'price_minor' => 99000,
            'is_active' => true,
            'is_public' => true,
        ]);
    });

    $alphaBody = (string) $this->getJson(pmUrl($alpha))
        ->assertOk()->getContent();

    $betaBody = (string) $this->getJson(pmUrl($beta))
        ->assertOk()->getContent();

    // The release-gate property, on the one surface strangers can reach.
    expect($alphaBody)->toContain('Haircut')->not->toContain('Beta Only Treatment')
        ->and($betaBody)->toContain('Beta Only Treatment')->not->toContain('Haircut');
});

it('answers an unknown center key exactly like an unknown page', function (): void {
    $this->getJson('/api/v1/menu/ctr_'.Str::lower(Str::random(32)))->assertNotFound();
    $this->getJson('/api/v1/menu/not-even-a-key')->assertNotFound();

    // A subdomain nobody registered is not a center either.
    $this->getJson(app(PlatformHosts::class)->centerUrl('nobody-here', '/api/v1/menu/nobody-here'))->assertNotFound();
});

it('has no menu until the center publishes one', function (): void {
    $center = $this->registerCenter();

    // Provisioning seeds and publishes a default menu, so this checks the
    // opposite case: a center whose published version is removed has no public
    // page rather than a page assembled from defaults nobody approved.
    $this->asCenter($center['tenant'], function (): void {
        MenuVersion::query()->delete();
    });

    $this->getJson(pmUrl($center))->assertNotFound();
});

it('shows only the branch that was asked for, and only if it is public', function (): void {
    $center = $this->registerCenter();
    seedPublishedMenu($center);

    $branches = $this->asCenter($center['tenant'], function (): array {
        $public = $this->seedBranch('Public Branch', isPublic: true);
        $hidden = $this->seedBranch('Back Office', isPublic: false);

        return ['public' => $public->uuid, 'hidden' => $hidden->uuid];
    });

    $this->getJson(pmUrl($center, "?branch={$branches['public']}"))
        ->assertOk()
        ->assertJsonPath('data.branch.name', 'Public Branch');

    // A hidden branch is not found, not silently swapped for another — showing
    // a customer the wrong address is worse than showing none.
    $this->getJson(pmUrl($center, "?branch={$branches['hidden']}"))->assertNotFound();

    $listed = $this->getJson(pmUrl($center))->assertOk()->json('data.branches');

    expect(array_column($listed, 'name'))->not->toContain('Back Office');
});

it('restricts a branch menu to the services offered there', function (): void {
    $center = $this->registerCenter();
    seedPublishedMenu($center);

    $second = $this->asCenter($center['tenant'], function (): Branch {
        $second = $this->seedBranch('Second');

        $main = Branch::main();

        /** @var Service $mainOnly */
        $mainOnly = Service::query()->create([
            'name' => TranslatedText::make('en', 'Main Only Service'),
            'duration_minutes' => 30,
            'price_minor' => 15000,
            'is_active' => true,
            'is_public' => true,
            'available_at_all_branches' => false,
        ]);

        $mainOnly->branches()->attach($main->id);

        return $second;
    });

    $atSecond = $this->getJson(pmUrl($center, "?branch={$second->uuid}"))->assertOk()->json('data.services');

    expect(array_column($atSecond, 'name'))
        ->toContain('Haircut')
        ->not->toContain('Main Only Service');
});

it('excludes anything inactive, archived or held back from the public', function (): void {
    $center = $this->registerCenter();
    seedPublishedMenu($center);

    $this->asCenter($center['tenant'], function (): void {
        Service::query()->create([
            'name' => TranslatedText::make('en', 'Inactive Service'),
            'duration_minutes' => 30, 'price_minor' => 1000,
            'is_active' => false, 'is_public' => true,
        ]);

        Service::query()->create([
            'name' => TranslatedText::make('en', 'Private Service'),
            'duration_minutes' => 30, 'price_minor' => 1000,
            'is_active' => true, 'is_public' => false,
        ]);

        Service::query()->create([
            'name' => TranslatedText::make('en', 'Archived Service'),
            'duration_minutes' => 30, 'price_minor' => 1000,
            'is_active' => true, 'is_public' => true,
            'archived_at' => now(),
        ]);

        ServiceCategory::query()->create([
            'name' => TranslatedText::make('en', 'Internal Category'),
            'is_active' => true, 'is_public' => false,
        ]);
    });

    $body = (string) $this->getJson(pmUrl($center))
        ->assertOk()->getContent();

    expect($body)->not->toContain('Inactive Service')
        ->not->toContain('Private Service')
        ->not->toContain('Archived Service')
        ->not->toContain('Internal Category')
        ->toContain('Haircut');
});

it('leaks nothing internal — no ids, notes, staff contacts or audit data', function (): void {
    $center = $this->registerCenter();
    seedPublishedMenu($center);

    $this->asCenter($center['tenant'], function (): void {
        $catalog = $this->seedCatalog();

        $catalog['service']->addInternalNote('Cost price is 8,000 — do not quote below 15,000.');

        $employee = $this->seedEmployee('Sara');
        $employee->forceFill(['status' => EmployeeStatus::Active])->save();

        // Turn the employees section on so the strictest case is exercised.
        $publisher = app(MenuPublisher::class);
        $published = $publisher->published();
        $sections = $published->sections;

        foreach ($sections as $i => $section) {
            if ($section['key'] === 'employees') {
                $sections[$i]['visible'] = true;
            }
        }

        $published->forceFill(['sections' => $sections])->save();
    });

    $body = (string) $this->getJson(pmUrl($center))
        ->assertOk()->getContent();

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($body, true);

    expect($body)
        // Internal notes are staff-only and must never reach a customer.
        ->not->toContain('Cost price')
        ->not->toContain('do not quote')
        // Nothing about staff beyond a display name.
        ->not->toContain('owner@alpha.test')
        ->not->toContain('password')
        ->not->toContain('is_owner')
        ->not->toContain('permission')
        ->not->toContain('internal_note')
        ->not->toContain('archived_at')
        ->not->toContain('cost');

    // Uuids only, never auto-increment ids (docs/08-AUDIT-SECURITY.md §19).
    $service = $decoded['data']['services'][0];

    expect($service)->toHaveKey('uuid')
        ->and($service)->not->toHaveKey('id')
        ->and($service)->not->toHaveKey('department_id')
        ->and($service)->not->toHaveKey('service_category_id');

    foreach ($decoded['data']['employees'] as $employee) {
        // Name and uuid. Nothing else exists on this shape at all.
        expect(array_keys($employee))->toBe(['uuid', 'name']);
    }
});

it('resolves an inheriting variation to a real price before a customer sees it', function (): void {
    $center = $this->registerCenter();
    seedPublishedMenu($center);

    $variations = $this->getJson(pmUrl($center))
        ->assertOk()->json('data.services.0.variations');

    expect($variations)->toHaveCount(3);

    foreach ($variations as $variation) {
        // A customer must never be shown a null price because a variation
        // inherits from its service (ADR-037).
        expect($variation['price']['amount'])->toBeInt()->toBeGreaterThan(0)
            ->and($variation['duration_minutes'])->toBeGreaterThan(0);
    }

    $standard = collect($variations)->firstWhere('name', 'Standard');

    expect($standard['price']['amount'])->toBe(20000);
});

it('renders the whole menu in a bounded number of queries', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->publishMenu();

        // Enough services that an N+1 would be obvious: naive rendering would
        // issue at least one query per service per relation.
        for ($i = 0; $i < 25; $i++) {
            $service = Service::query()->create([
                'name' => TranslatedText::make('en', "Service {$i}"),
                'duration_minutes' => 30,
                'price_minor' => 10000 + $i,
                'is_active' => true,
                'is_public' => true,
            ]);

            $service->variations()->create([
                'name' => TranslatedText::make('en', 'Standard'),
                'is_active' => true,
            ]);
        }
    });

    $queries = 0;

    Event::listen(function (QueryExecuted $event) use (&$queries): void {
        if ($event->connectionName === 'tenant') {
            $queries++;
        }
    });

    $response = $this->getJson(pmUrl($center))->assertOk();

    expect($response->json('data.services'))->toHaveCount(25)
        // The count is bounded by the number of RELATIONS, not by the number of
        // services. A regression here is an N+1 someone just introduced.
        ->and($queries)->toBeLessThan(20);
});

it('clears the tenant context after a public menu request', function (): void {
    $center = $this->registerCenter();
    seedPublishedMenu($center);

    $this->getJson(pmUrl($center))->assertOk();

    // A guest request leaves no more residue than an authenticated one.
    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('refuses a public key that disagrees with the host it arrived on', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    seedPublishedMenu($alpha);
    seedPublishedMenu($beta);

    // Two sources naming different centers is never resolved by preferring
    // one, on the public surface either: on Beta's host, Alpha's slug in the
    // path is "no center here" — the answer any unknown address gets — and
    // nothing of either menu is served.
    $betaSlug = (string) $beta['registration']->requested_slug;
    $alphaSlug = (string) $alpha['registration']->requested_slug;

    $response = $this->getJson(app(PlatformHosts::class)->centerUrl($betaSlug, '/api/v1/menu/'.$alphaSlug))
        ->assertNotFound();

    expect((string) $response->getContent())->not->toContain('Haircut');
});

it('serves the menu from the center\'s own registered subdomain', function (): void {
    $center = $this->registerCenter();
    seedPublishedMenu($center);

    // The registered subdomain is authoritative (ADR-076): the center resolves
    // by host, exactly as the authenticated surfaces do.
    $this->getJson(pmUrl($center))
        ->assertOk()
        ->assertJsonPath('data.services.0.name', 'Haircut');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
