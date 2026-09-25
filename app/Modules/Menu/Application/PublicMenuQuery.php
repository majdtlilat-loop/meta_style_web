<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Branches\Domain\Models\BranchWorkingHour;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Menu\Domain\MenuPresentation;
use Illuminate\Support\Collection;

/**
 * Everything a guest-facing menu needs, in a fixed number of queries.
 *
 * N+1 IS THE FAILURE MODE HERE, not a style preference. A menu is one page
 * rendering a whole catalog: categories, services, each service's variations,
 * add-ons and images. Rendered naively that is one query per service per
 * relation — a center with 120 services would issue several hundred queries for
 * one guest, and the page is the one customers actually open.
 *
 * So everything is loaded up front, in bounded queries, and the renderer does
 * no database work at all. `tests/Feature/Menu/PublicMenuTest.php` asserts the
 * query count so a future relation added to the template fails the build rather
 * than the production database.
 *
 * VISIBILITY IS AN ALLOW-LIST. Every query filters on active + public + not
 * archived. Nothing internal — notes, costs, employee logins, ids — is loaded
 * at all, so it cannot leak by being rendered accidentally.
 *
 * The page model around the data — the brand the menu inherits, the style
 * tokens, the service groups in the order the owner chose, opening hours and
 * contact links — is built here too, so the template only prints.
 */
final class PublicMenuQuery
{
    public function __construct(
        private readonly MenuPublisher $publisher,
        private readonly PublicBrand $brand,
        private readonly MenuStyle $style,
    ) {}

    /**
     * @return array{
     *     presentation: MenuPresentation,
     *     branch: Branch|null,
     *     branches: Collection<int, Branch>,
     *     categories: Collection<int, ServiceCategory>,
     *     departments: Collection<int, Department>,
     *     services: Collection<int, Service>,
     *     employees: Collection<int, Employee>,
     *     currency: Currency,
     *     brand: array<string, string|null>|null,
     *     style: array{vars: array<string, string>, classes: list<string>, dark: bool},
     *     logo: string|null,
     *     groups: list<array{anchor: string|null, title: TranslatedText|null, services: Collection<int, Service>}>,
     *     featured: Collection<int, Service>,
     *     hours: list<array{day: int, times: string}>,
     *     contact: array{phone: string|null, whatsapp: string|null, email: string|null, map_url: string|null},
     * }|null
     */
    public function forBranch(?string $branchUuid = null): ?array
    {
        $published = $this->publisher->published();

        if ($published === null) {
            // A center whose menu has never been published has no public page.
            // Falling back to defaults would publish a menu nobody approved.
            return null;
        }

        return $this->forPresentation($published->presentation(), $branchUuid);
    }

    /**
     * The same page for a presentation that is not (yet) live — the Manager's
     * draft preview. Never reachable from a guest route.
     *
     * @return array<string, mixed>|null
     */
    public function forPresentation(MenuPresentation $presentation, ?string $branchUuid = null): ?array
    {
        /** @var Collection<int, Branch> $branches */
        $branches = Branch::query()->publiclyVisible()->get();

        $branch = $branchUuid === null
            ? $branches->first()
            : $branches->firstWhere('uuid', $branchUuid);

        if ($branchUuid !== null && $branch === null) {
            // A named branch that is not publicly visible is "not found", not
            // "here is a different branch" — silently substituting one would
            // show a customer the wrong address.
            return null;
        }

        $services = $this->services($branch, $presentation);
        $categories = $this->categories($services);
        $departments = $this->departments($services);
        $brand = $this->brand->resolve();
        $style = $this->style->for($presentation, $brand);

        $featuredLimit = $presentation->sectionConfig('featured_services')['limit'] ?? 6;

        return [
            'presentation' => $presentation,
            'branch' => $branch,
            'branches' => $branches,
            'categories' => $categories,
            'departments' => $departments,
            'services' => $services,
            'employees' => $presentation->hasSection('employees')
                ? $this->employees($branch)
                : new Collection,
            'currency' => Currency::default(),
            'brand' => $brand,
            'style' => $style,
            // The dark logo on a dark page when the brand has one.
            'logo' => $brand === null ? null : (($style['dark'] ? $brand['logo_dark_url'] : null) ?? $brand['logo_url']),
            'groups' => $this->groups($presentation, $services, $categories, $departments),
            'featured' => $services->take(is_int($featuredLimit) ? $featuredLimit : 6)->values(),
            'hours' => $branch instanceof Branch && $presentation->hasSection('location') ? $this->hours($branch) : [],
            'contact' => $this->contact($branch),
        ];
    }

    /**
     * @return Collection<int, Service>
     */
    private function services(?Branch $branch, MenuPresentation $presentation): Collection
    {
        $query = Service::query()
            ->publiclyVisible()
            ->with([
                // Only the variations and add-ons a customer may see. Filtering
                // in the eager-load constraint rather than afterwards keeps the
                // inactive rows out of memory entirely.
                'variations' => fn ($q) => $q->where('is_active', true),
                'addons' => fn ($q) => $q->where('is_active', true),
                'media',
                'category',
                'department',
            ]);

        if ($branch instanceof Branch) {
            $query->atBranch($branch->id);
        }

        $limit = $presentation->hasSection('all_services')
            ? null
            : $presentation->sectionConfig('featured_services')['limit'] ?? null;

        if (is_int($limit)) {
            $query->limit($limit);
        }

        /** @var Collection<int, Service> $services */
        $services = $query->get();

        return $services;
    }

    /**
     * Categories that actually have something in them.
     *
     * A menu heading with nothing under it looks like a bug to a customer and
     * like an empty shop to everyone else.
     *
     * @param  Collection<int, Service>  $services
     * @return Collection<int, ServiceCategory>
     */
    private function categories(Collection $services): Collection
    {
        $ids = $services->pluck('service_category_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, ServiceCategory> $categories */
        $categories = ServiceCategory::query()
            ->publiclyVisible()
            ->whereIn('id', $ids)
            ->with('media')
            ->get();

        return $categories;
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return Collection<int, Department>
     */
    private function departments(Collection $services): Collection
    {
        $ids = $services->pluck('department_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return new Collection;
        }

        /** @var Collection<int, Department> $departments */
        $departments = Department::query()->active()->whereIn('id', $ids)->with('media')->get();

        return $departments;
    }

    /**
     * The service list the way the owner asked for it: by category, by
     * department, or as one list. Groups that would be empty are left out, and
     * whatever has no group lands in a final untitled one.
     *
     * @param  Collection<int, Service>  $services
     * @param  Collection<int, ServiceCategory>  $categories
     * @param  Collection<int, Department>  $departments
     * @return list<array{anchor: string|null, title: TranslatedText|null, services: Collection<int, Service>}>
     */
    private function groups(MenuPresentation $presentation, Collection $services, Collection $categories, Collection $departments): array
    {
        $groupBy = $presentation->sectionConfig('all_services')['group_by'] ?? 'category';

        [$owners, $column, $prefix] = match ($groupBy) {
            'category' => [$categories, 'service_category_id', 'category-'],
            'department' => [$departments, 'department_id', 'department-'],
            default => [null, null, null],
        };

        if ($owners === null || $column === null || $owners->isEmpty()) {
            return [['anchor' => null, 'title' => null, 'services' => $services->values()]];
        }

        $groups = [];

        foreach ($owners as $owner) {
            $inGroup = $services->where($column, $owner->id)->values();

            if ($inGroup->isNotEmpty()) {
                $groups[] = ['anchor' => $prefix.$owner->uuid, 'title' => $owner->name, 'services' => $inGroup];
            }
        }

        $known = $owners->pluck('id')->all();
        $rest = $services->reject(static fn (Service $s): bool => in_array($s->{$column}, $known, true))->values();

        if ($rest->isNotEmpty()) {
            $groups[] = ['anchor' => null, 'title' => null, 'services' => $rest];
        }

        return $groups;
    }

    /**
     * Staff shown on the public menu.
     *
     * Only ACTIVE employees, and only their name. No login, no email, no phone,
     * no branch assignment, no permissions — the resource that renders this
     * lists the fields it emits explicitly, so nothing can be added to the
     * model and start appearing here (docs/08-AUDIT-SECURITY.md §19).
     *
     * @return Collection<int, Employee>
     */
    private function employees(?Branch $branch): Collection
    {
        $query = Employee::query()
            ->where('status', EmployeeStatus::Active->value)
            ->orderBy('id');

        if ($branch instanceof Branch) {
            $query->whereHas('branches', fn ($q) => $q->whereKey($branch->id));
        }

        /** @var Collection<int, Employee> $employees */
        $employees = $query->get();

        return $employees;
    }

    /**
     * Opening hours, one row per open weekday, in the branch's own local
     * times (they are stored that way).
     *
     * @return list<array{day: int, times: string}>
     */
    private function hours(Branch $branch): array
    {
        $rows = [];

        // Loaded for the ONE branch shown (a single query), not for every
        // public branch, and never lazily: the branch came from a collection.
        $branch->loadMissing('workingHours');

        foreach ($branch->workingHours->sortBy(['day_of_week', 'opens_at'])->groupBy('day_of_week') as $day => $intervals) {
            $rows[] = [
                'day' => (int) $day,
                'times' => $intervals
                    ->map(static fn (BranchWorkingHour $h): string => mb_substr($h->opens_at, 0, 5).'–'.mb_substr($h->closes_at, 0, 5))
                    ->implode(', '),
            ];
        }

        return $rows;
    }

    /**
     * A branch's published business contact details, as links a guest can
     * open. The map link is offered only when it is a real http(s) address.
     *
     * @return array{phone: string|null, whatsapp: string|null, email: string|null, map_url: string|null}
     */
    private function contact(?Branch $branch): array
    {
        if (! $branch instanceof Branch) {
            return ['phone' => null, 'whatsapp' => null, 'email' => null, 'map_url' => null];
        }

        $whatsapp = preg_replace('/\D/', '', (string) $branch->whatsapp) ?? '';
        $map = trim((string) $branch->map_url);

        return [
            'phone' => $branch->phone !== null && trim($branch->phone) !== '' ? trim($branch->phone) : null,
            'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
            'email' => $branch->email !== null && filter_var($branch->email, FILTER_VALIDATE_EMAIL) !== false ? $branch->email : null,
            'map_url' => preg_match('#^https?://#i', $map) === 1 ? $map : null,
        ];
    }
}
