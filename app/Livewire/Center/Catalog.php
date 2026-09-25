<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Money\Currency;
use App\Livewire\Center\Catalog\Concerns\ServiceRowActions;
use App\Livewire\Center\Catalog\LibraryPresenter;
use App\Modules\Catalog\Application\Actions\MoveService;
use App\Modules\Catalog\Application\Actions\MoveServiceCategory;
use App\Modules\Catalog\Application\Actions\SaveServiceCategory;
use App\Modules\Catalog\Application\CatalogQuery;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The service library: categories down the side, their services as an
 * ordered list, and everything an owner does to them from here.
 *
 * Orchestration only. Every write is one Catalog Action (permission, lock,
 * transaction, audit); the forms live in child components — the service and
 * category drawers, the photo gallery and the quick add — which announce
 * `catalog-changed` and this page re-reads the database.
 *
 * ORDERING is by intent: a drag or a Move up / Move down button sends one
 * "this item to that position of that list", and the Action re-derives the
 * order from locked rows. A list the page is showing through a filter is not
 * the whole list, so ordering is offered only on the unfiltered view.
 */
#[Layout('components.layouts.app')]
final class Catalog extends Component
{
    use ServiceRowActions;

    /** '' = every service, 'none' = uncategorised, otherwise a category uuid. */
    #[Url(except: '')]
    public string $category = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** '' = live (active and inactive), 'active', 'inactive' or 'archived'. */
    #[Url(except: '')]
    public string $status = '';

    /** '', 'public', 'hidden', 'online' or 'offline'. */
    #[Url(except: '')]
    public string $visibility = '';

    public function mount(CatalogQuery $query): void
    {
        $query->authorize($this->actor());

        $this->status = in_array($this->status, CatalogQuery::STATUSES, true) ? $this->status : '';
        $this->visibility = in_array($this->visibility, CatalogQuery::VISIBILITIES, true) ? $this->visibility : '';
    }

    #[On('catalog-changed')]
    public function refreshLibrary(string $message = ''): void
    {
        if ($message !== '') {
            $this->flash($message);
        }
    }

    public function selectCategory(string $key): void
    {
        $this->category = $key;
    }

    public function setStatus(string $status): void
    {
        $this->status = in_array($status, CatalogQuery::STATUSES, true) ? $status : '';
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->visibility = '';
    }

    // ---------------------------------------------------------- ordering

    public function moveCategory(string $uuid, int $toIndex): void
    {
        $this->attempt(fn () => app(MoveServiceCategory::class)($this->categoryOrFail($uuid), $toIndex, $this->actor()));
    }

    public function moveCategoryBy(string $uuid, int $delta): void
    {
        $this->attempt(fn () => app(MoveServiceCategory::class)->step($this->categoryOrFail($uuid), $delta <=> 0, $this->actor()));
    }

    /**
     * A drop: within the same list (two arguments), onto another category's
     * list at a position, or onto a category in the sidebar ('end:{uuid}',
     * which appends).
     */
    public function moveService(string $uuid, int $toIndex, ?string $container = null): void
    {
        $this->attempt(function () use ($uuid, $toIndex, $container): void {
            $service = $this->serviceOrFail($uuid);
            $move = app(MoveService::class);

            if ($container === null) {
                $move($service, $toIndex, $this->actor());

                return;
            }

            $append = str_starts_with($container, 'end:');
            $key = $append ? mb_substr($container, 4) : $container;
            $target = $key === 'none' ? null : $this->categoryOrFail($key);

            // Dropping a service on the category it is already in changes nothing.
            if ($append && $target?->id === $service->service_category_id) {
                return;
            }

            $move->toCategory($service, $target, $append ? null : $toIndex, $this->actor());
        });
    }

    public function moveServiceBy(string $uuid, int $delta): void
    {
        $this->attempt(fn () => app(MoveService::class)->step($this->serviceOrFail($uuid), $delta <=> 0, $this->actor()));
    }

    // ------------------------------------------------ category quick actions

    public function setCategoryActive(string $uuid, bool $on): void
    {
        $this->saveCategoryFlags($uuid, isActive: $on);
    }

    public function setCategoryPublic(string $uuid, bool $on): void
    {
        $this->saveCategoryFlags($uuid, isPublic: $on);
    }

    public function archiveCategory(string $uuid): void
    {
        $archived = $this->attempt(
            fn () => app(SaveServiceCategory::class)->archive($this->categoryOrFail($uuid), $this->actor()),
            __('manager_catalog.notices.category_archived'),
        );

        if ($archived && $this->category === $uuid) {
            $this->category = '';
        }
    }

    public function restoreCategory(string $uuid): void
    {
        $this->attempt(
            fn () => app(SaveServiceCategory::class)->restore($this->categoryOrFail($uuid), $this->actor()),
            __('manager_catalog.notices.category_restored'),
        );
    }

    public function render(CatalogQuery $query, LibraryPresenter $present): View
    {
        $viewer = $this->actor();
        $categories = $query->categories($viewer);

        // A category that no longer exists (archived elsewhere, or a stale
        // link) falls back to the whole library rather than an empty page.
        $selected = $this->category === '' || $this->category === 'none'
            ? null
            : $categories->firstWhere('uuid', $this->category);

        if ($this->category !== '' && $this->category !== 'none' && $selected === null) {
            $this->category = '';
        }

        $filtered = $this->search !== '' || $this->status !== '' || $this->visibility !== '';
        $services = $query->services([
            'category' => $this->category === '' ? null : $this->category,
            'search' => $this->search,
            'status' => $this->status,
            'visibility' => $this->visibility,
        ], $viewer);

        $can = [
            'create' => $viewer->hasPermission(Permission::ServiceCreate),
            'update' => $viewer->hasPermission(Permission::ServiceUpdate),
            'archive' => $viewer->hasPermission(Permission::ServiceArchive),
            'categories' => $viewer->hasPermission(Permission::CategoryManage),
        ];

        $categoryRows = $categories->map(fn (ServiceCategory $c): array => $present->category($c))->values()->all();
        $library = $query->counts(null, $viewer);
        $scoped = $this->category === '' ? $library : $query->counts($this->category, $viewer);
        $sections = $present->sections($services->all(), $categoryRows, $this->category, $filtered, $can['update']);

        return view('livewire.center.catalog', [
            'categories' => $categoryRows,
            'archivedCategories' => $can['categories']
                ? $query->archivedCategories($viewer)->map(fn (ServiceCategory $c): array => ['uuid' => $c->uuid, 'name' => $present->name($c->name)])->all()
                : [],
            'selected' => $selected instanceof ServiceCategory ? $present->category($selected) : null,
            'currentLabel' => $selected instanceof ServiceCategory
                ? $present->name($selected->name)
                : ($this->category === 'none' ? __('manager_catalog.categories.uncategorised') : __('manager_catalog.categories.all')),
            'sections' => $sections,
            'library' => $library,
            'statusTabs' => $present->statusTabs($scoped),
            'visibilityOptions' => $present->visibilityOptions(),
            'emptyLibrary' => $library['live'] === 0 && $library['archived'] === 0 && $categoryRows === [],
            'noMatches' => $filtered && $services->isEmpty(),
            'filtered' => $filtered,
            'can' => $can,
            'currency' => Currency::default()->value,
            'moving' => $this->movingRow($present),
        ]);
    }

    /**
     * @return array{name: string, category: string}|null
     */
    private function movingRow(LibraryPresenter $present): ?array
    {
        $service = $this->movingService === null
            ? null
            : Service::query()->with('category')->where('uuid', $this->movingService)->first();

        return $service instanceof Service
            ? ['name' => $present->name($service->name), 'category' => $service->category->uuid ?? 'none']
            : null;
    }

    private function saveCategoryFlags(string $uuid, ?bool $isActive = null, ?bool $isPublic = null): void
    {
        $this->attempt(function () use ($uuid, $isActive, $isPublic): void {
            $category = $this->categoryOrFail($uuid);

            // Every field is sent back as it is, so a quick toggle can never
            // reset the description or the position.
            app(SaveServiceCategory::class)(
                name: $category->name->all(),
                actingUser: $this->actor(),
                category: $category,
                description: $category->description?->all() ?? [],
                isActive: $isActive ?? $category->is_active,
                isPublic: $isPublic ?? $category->is_public,
            );
        }, __('manager_catalog.notices.category_saved'));
    }

    private function categoryOrFail(string $uuid): ServiceCategory
    {
        return ServiceCategory::query()->where('uuid', $uuid)->first() ?? throw new ModelNotFoundException;
    }
}
