<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Application\Actions\ArchiveService;
use App\Modules\Catalog\Application\Actions\MoveService;
use App\Modules\Catalog\Application\Actions\MoveServiceCategory;
use App\Modules\Catalog\Application\Actions\SaveService;
use App\Modules\Catalog\Application\Actions\SaveServiceCategory;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Library ordering — categories and services
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 4 (Manager services library).
|
| One intent per call, re-derived from locked rows: a service's sort_order is
| its position in the WHOLE library (categories in order, uncategorised
| last), renumbered 0..N-1 after every move. Legacy rows all start at 0.
|
*/

function orderingCategory(string $name): ServiceCategory
{
    /** @var ServiceCategory $category */
    $category = ServiceCategory::query()->create([
        'name' => TranslatedText::make('en', $name),
        'is_active' => true,
        'is_public' => true,
        'sort_order' => 0,
    ]);

    return $category;
}

function orderingService(string $name, ?ServiceCategory $category): Service
{
    /** @var Service $service */
    $service = Service::query()->create([
        'name' => TranslatedText::make('en', $name),
        'service_category_id' => $category?->id,
        'duration_minutes' => 30,
        'price_minor' => 10000,
        'sort_order' => 0,
    ]);

    return $service;
}

/**
 * @return list<string> live service names of one category (null = uncategorised), in library order
 */
function orderingNames(?ServiceCategory $category): array
{
    return Service::query()
        ->whereNull('archived_at')
        ->when(
            $category instanceof ServiceCategory,
            fn ($q) => $q->where('service_category_id', $category?->id),
            fn ($q) => $q->whereNull('service_category_id'),
        )
        ->orderBy('sort_order')->orderBy('id')->get()
        ->map(fn (Service $s): string => $s->name->get('en'))->all();
}

/**
 * @return list<int> every live service's sort_order, in library order
 */
function orderingPositions(): array
{
    return Service::query()->whereNull('archived_at')->orderBy('sort_order')->orderBy('id')
        ->pluck('sort_order')->map(fn ($v): int => (int) $v)->all();
}

/**
 * A: a1 a2 a3 · B: b1 b2 · uncategorised: u1 — all at the legacy sort_order 0.
 *
 * @return array{a: ServiceCategory, b: ServiceCategory, s: array<string, Service>}
 */
function orderingLibrary(): array
{
    $a = orderingCategory('A');
    $b = orderingCategory('B');

    $s = [];
    foreach (['a1' => $a, 'a2' => $a, 'a3' => $a, 'b1' => $b, 'b2' => $b, 'u1' => null] as $name => $category) {
        $s[$name] = orderingService($name, $category);
    }

    return ['a' => $a, 'b' => $b, 's' => $s];
}

it('reorders a service inside its category and renumbers the whole library contiguously', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 'b' => $b, 's' => $s] = orderingLibrary();

        app(MoveService::class)($s['a3'], 0, $owner);

        // Read back fresh from the database: the order survives a reload.
        expect(orderingNames($a))->toBe(['a3', 'a1', 'a2'])
            ->and(orderingNames($b))->toBe(['b1', 'b2'])
            ->and(orderingNames(null))->toBe(['u1'])
            ->and(orderingPositions())->toBe([0, 1, 2, 3, 4, 5])
            // Categories in order, uncategorised last.
            ->and($s['b1']->refresh()->sort_order)->toBe(3)
            ->and($s['u1']->refresh()->sort_order)->toBe(5);
    });
});

it('moves a service into another category at a position, or appends it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 'b' => $b, 's' => $s] = orderingLibrary();

        app(MoveService::class)->toCategory($s['a1'], $b, 1, $owner);

        expect(orderingNames($a))->toBe(['a2', 'a3'])
            ->and(orderingNames($b))->toBe(['b1', 'a1', 'b2'])
            ->and($s['a1']->refresh()->service_category_id)->toBe($b->id);

        // No position: the end of the list. Null: uncategorised.
        app(MoveService::class)->toCategory($s['b2'], null, null, $owner);

        expect(orderingNames(null))->toBe(['u1', 'b2'])
            ->and(orderingPositions())->toBe([0, 1, 2, 3, 4, 5]);
    });
});

it('clamps an index outside the list and steps one place at a time', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 's' => $s] = orderingLibrary();

        app(MoveService::class)($s['a1'], 99, $owner);
        expect(orderingNames($a))->toBe(['a2', 'a3', 'a1']);

        app(MoveService::class)($s['a1'], -7, $owner);
        expect(orderingNames($a))->toBe(['a1', 'a2', 'a3']);

        app(MoveService::class)->step($s['a1'], 1, $owner);
        expect(orderingNames($a))->toBe(['a2', 'a1', 'a3']);

        // Already first: a step up changes nothing and writes no audit row.
        $before = TenantAuditLog::query()->where('action', 'catalog.service.moved')->count();
        app(MoveService::class)->step($s['a2'], -1, $owner);

        expect(orderingNames($a))->toBe(['a2', 'a1', 'a3'])
            ->and(TenantAuditLog::query()->where('action', 'catalog.service.moved')->count())->toBe($before);
    });
});

it('moves categories, and their services follow in the library order', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 'b' => $b, 's' => $s] = orderingLibrary();

        $to = app(MoveServiceCategory::class)($b, 0, $owner);

        $categories = ServiceCategory::query()->orderBy('sort_order')->orderBy('id')->get();

        expect($to)->toBe(0)
            ->and($categories->pluck('uuid')->all())->toBe([$b->uuid, $a->uuid])
            ->and($categories->pluck('sort_order')->map(fn ($v): int => (int) $v)->all())->toBe([0, 1])
            // The flat list every other screen reads now starts with B.
            ->and(Service::query()->orderBy('sort_order')->orderBy('id')->get()->map(fn (Service $x): string => $x->name->get('en'))->all())
            ->toBe(['b1', 'b2', 'a1', 'a2', 'a3', 'u1']);

        app(MoveServiceCategory::class)->step($b, 1, $owner);

        expect(ServiceCategory::query()->orderBy('sort_order')->orderBy('id')->pluck('uuid')->all())->toBe([$a->uuid, $b->uuid])
            ->and($s['u1']->refresh()->sort_order)->toBe(5);
    });
});

it('refuses to move an archived service or into an archived category', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 'b' => $b, 's' => $s] = orderingLibrary();

        app(ArchiveService::class)($s['a2'], $owner);

        expect(fn () => app(MoveService::class)($s['a2'], 0, $owner))
            ->toThrow(ValidationException::class);

        // The caller still holds the category model from before it was archived.
        $stale = ServiceCategory::query()->findOrFail($b->id);
        app(SaveServiceCategory::class)->archive($b, $owner);

        expect(fn () => app(MoveService::class)->toCategory($s['a1'], $stale, 0, $owner))
            ->toThrow(ValidationException::class)
            ->and(fn () => app(MoveServiceCategory::class)($stale, 0, $owner))
            ->toThrow(ValidationException::class)
            ->and($s['a1']->refresh()->service_category_id)->toBe($a->id);
    });
});

it('refuses to reorder without the right permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        ['a' => $a, 's' => $s] = orderingLibrary();
        $viewer = $this->staffWith([Permission::ServiceView, Permission::CategoryView]);

        expect(fn () => app(MoveService::class)($s['a3'], 0, $viewer))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(MoveServiceCategory::class)($a, 1, $viewer))
            ->toThrow(AuthorizationException::class)
            ->and(orderingNames($a))->toBe(['a1', 'a2', 'a3']);
    });
});

it('audits a move with the category and position before and after', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $entries = $this->asCenter($center['tenant'], function () use ($owner): array {
        ['a' => $a, 'b' => $b, 's' => $s] = orderingLibrary();

        app(MoveService::class)->toCategory($s['a2'], $b, 0, $owner);
        app(MoveServiceCategory::class)($b, 0, $owner);

        return [
            'service' => TenantAuditLog::query()->where('action', 'catalog.service.moved')->latest('id')->firstOrFail(),
            'category' => TenantAuditLog::query()->where('action', 'catalog.category.moved')->latest('id')->firstOrFail(),
            'a' => $a->uuid,
            'b' => $b->uuid,
        ];
    });

    expect($entries['service']->before)->toBe(['category' => $entries['a'], 'position' => 1])
        ->and($entries['service']->after)->toBe(['category' => $entries['b'], 'position' => 0])
        ->and($entries['category']->before)->toBe(['position' => 1])
        ->and($entries['category']->after)->toBe(['position' => 0]);
});

it('appends a new service to its category, keeps an edited one in place and appends on a category change', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 'b' => $b, 's' => $s] = orderingLibrary();

        $new = app(SaveService::class)(new ServiceInput(
            name: ['en' => 'a4'], durationMinutes: 30, priceMinor: 1000, serviceCategoryId: $a->id,
        ), $owner);

        expect(orderingNames($a))->toBe(['a1', 'a2', 'a3', 'a4'])
            ->and(orderingPositions())->toBe([0, 1, 2, 3, 4, 5, 6]);

        app(MoveService::class)($new, 0, $owner);

        // An edit that does not mention a position keeps it.
        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'a4'], durationMinutes: 45, priceMinor: 1500, serviceCategoryId: $a->id,
        ), $owner, $new->refresh());

        expect(orderingNames($a))->toBe(['a4', 'a1', 'a2', 'a3']);

        // A new category: the end of that category.
        app(SaveService::class)(new ServiceInput(
            name: ['en' => 'a4'], durationMinutes: 45, priceMinor: 1500, serviceCategoryId: $b->id,
        ), $owner, $new->refresh());

        expect(orderingNames($b))->toBe(['b1', 'b2', 'a4'])
            ->and(orderingNames($a))->toBe(['a1', 'a2', 'a3'])
            ->and(orderingPositions())->toBe([0, 1, 2, 3, 4, 5, 6]);
    });
});

it('appends new and restored categories last, and uncategorises an archived one in order', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 'b' => $b] = orderingLibrary();

        $c = app(SaveServiceCategory::class)(['en' => 'C'], $owner);

        expect(ServiceCategory::query()->orderBy('sort_order')->orderBy('id')->pluck('uuid')->all())
            ->toBe([$a->uuid, $b->uuid, $c->uuid]);

        app(SaveServiceCategory::class)->archive($a, $owner);

        // A's services survive, uncategorised, after the ones already there.
        expect(orderingNames(null))->toBe(['u1', 'a1', 'a2', 'a3'])
            ->and(orderingPositions())->toBe([0, 1, 2, 3, 4, 5])
            ->and(TenantAuditLog::query()->where('action', 'catalog.category.archived')->latest('id')->firstOrFail()->after)
            ->toBe(['detached_services' => 3]);

        $restored = app(SaveServiceCategory::class)->restore($a->refresh(), $owner);

        expect($restored->archived_at)->toBeNull()
            ->and($restored->is_active)->toBeFalse()
            ->and($restored->is_public)->toBeFalse()
            ->and(ServiceCategory::query()->whereNull('archived_at')->orderBy('sort_order')->orderBy('id')->pluck('uuid')->all())
            ->toBe([$b->uuid, $c->uuid, $a->uuid]);
    });
});

it('brings a restored service back at the end of its category', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        ['a' => $a, 's' => $s] = orderingLibrary();

        app(ArchiveService::class)($s['a1'], $owner);
        app(MoveService::class)($s['a3'], 0, $owner);
        app(ArchiveService::class)->restore($s['a1']->refresh(), $owner);

        expect(orderingNames($a))->toBe(['a3', 'a2', 'a1'])
            ->and(orderingPositions())->toBe([0, 1, 2, 3, 4, 5]);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
