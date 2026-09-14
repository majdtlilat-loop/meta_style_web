<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Kernel\Money\Currency;
use App\Modules\Branches\Domain\Models\Branch;
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
 */
final class PublicMenuQuery
{
    public function __construct(private readonly MenuPublisher $publisher) {}

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

        $presentation = $published->presentation();

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

        return [
            'presentation' => $presentation,
            'branch' => $branch,
            'branches' => $branches,
            'categories' => $this->categories($services),
            'departments' => $this->departments($services),
            'services' => $services,
            'employees' => $presentation->hasSection('employees')
                ? $this->employees($branch)
                : new Collection,
            'currency' => Currency::default(),
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
}
