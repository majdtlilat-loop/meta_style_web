<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Menu\Application\MenuPublisher;
use Illuminate\Support\Str;

/**
 * Builds a realistic catalog inside the CURRENT tenant context.
 *
 * Every method here assumes a tenant is already bound — call them inside
 * `asCenter()`. That is deliberate: a helper that bound its own tenant would
 * hide exactly the context mistakes these tests exist to catch.
 *
 * Models are created directly rather than through the Actions, because these
 * are fixtures. Tests that care about authorization, audit or validation call
 * the Actions themselves.
 */
trait SeedsCatalog
{
    /**
     * A department, a category, and one three-variation service with an add-on.
     *
     * @return array{department: Department, category: ServiceCategory, service: Service, addon: ServiceAddon}
     */
    protected function seedCatalog(): array
    {
        /** @var Department $department */
        $department = Department::query()->create([
            'name' => TranslatedText::fromArray(['en' => 'Hair', 'ar' => 'الشعر']),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        /** @var ServiceCategory $category */
        $category = ServiceCategory::query()->create([
            'name' => TranslatedText::fromArray(['en' => 'Hair Services', 'ar' => 'خدمات الشعر']),
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        /** @var Service $service */
        $service = Service::query()->create([
            'department_id' => $department->id,
            'service_category_id' => $category->id,
            'name' => TranslatedText::fromArray(['en' => 'Haircut', 'ar' => 'قص شعر']),
            'short_description' => TranslatedText::fromArray(['en' => 'A classic cut']),
            'duration_minutes' => 30,
            // IQD, exponent 0: twenty thousand dinars is the integer 20000.
            'price_minor' => 20000,
            'is_active' => true,
            'is_public' => true,
            'is_online_bookable' => true,
            'available_at_all_branches' => true,
            'sort_order' => 0,
        ]);

        $service->variations()->createMany([
            [
                'name' => TranslatedText::fromArray(['en' => 'Short Hair']),
                'price_minor' => 20000,
                'is_active' => true,
                'sort_order' => 0,
            ],
            [
                'name' => TranslatedText::fromArray(['en' => 'Medium Hair']),
                'price_minor' => 25000,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                // No price and no duration: inherits from the service, and must
                // keep inheriting when the service's price changes (ADR-037).
                'name' => TranslatedText::fromArray(['en' => 'Standard']),
                'price_minor' => null,
                'duration_minutes' => null,
                'is_active' => true,
                'sort_order' => 2,
            ],
        ]);

        /** @var ServiceAddon $addon */
        $addon = ServiceAddon::query()->create([
            'name' => TranslatedText::fromArray(['en' => 'Hair Wash', 'ar' => 'غسيل شعر']),
            'price_minor' => 5000,
            'duration_minutes' => 10,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $service->addons()->attach($addon->id, ['sort_order' => 0]);

        return compact('department', 'category', 'service', 'addon');
    }

    /**
     * Publishes the seeded default menu so the public page is reachable.
     */
    protected function publishMenu(): void
    {
        app(MenuPublisher::class)->seed();
    }

    protected function seedEmployee(string $name = 'Stylist', ?Branch $branch = null): Employee
    {
        /** @var Employee $employee */
        $employee = Employee::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name]),
            'status' => EmployeeStatus::Active,
        ]);

        if ($branch instanceof Branch) {
            $employee->branches()->attach($branch->id);
        }

        return $employee;
    }

    /**
     * A second branch, so branch-scoped behaviour has something to scope to.
     */
    protected function seedBranch(string $name = 'Second Branch', bool $isPublic = true): Branch
    {
        /** @var Branch $branch */
        $branch = Branch::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name]),
            'timezone' => 'Asia/Baghdad',
            'is_active' => true,
            'is_public' => $isPublic,
            'is_main' => false,
            'sort_order' => 1,
        ]);

        return $branch;
    }

    /**
     * A customer, created directly. Fixtures only — tests that care about
     * authorization, validation or audit call the Action instead.
     */
    protected function seedCustomer(
        string $name = 'Sara Ahmed',
        ?string $phone = '0750 123 4567',
        ?string $email = null,
    ): Customer {
        $parsed = PhoneNumber::parse($phone);

        /** @var Customer $customer */
        $customer = Customer::query()->create([
            'name' => $name,
            'phone' => $parsed?->e164,
            'phone_display' => $parsed?->display,
            'email' => $email,
            'source' => CustomerSource::Staff,
        ]);

        return $customer;
    }

    /**
     * A staff account holding exactly the permissions named, and nothing else.
     *
     * Most of the PII suite is about what someone WITHOUT a permission sees, so
     * the roles have to be built precisely rather than borrowed.
     *
     * @param  list<Permission>  $permissions
     */
    protected function staffWith(array $permissions, string $email = 'limited@alpha.test'): User
    {
        /** @var User $user */
        $user = User::query()->create([
            'name' => 'Limited Staff',
            'email' => $email,
            'is_active' => true,
            'is_owner' => false,
            'all_branches' => true,
        ]);

        /** @var Role $role */
        $role = Role::query()->create([
            'key' => 'test-role-'.Str::lower(Str::random(8)),
            'name' => TranslatedText::make('en', 'Test role'),
            'is_system' => false,
        ]);

        $role->syncPermissions($permissions);

        $user->roles()->sync([$role->id]);
        $user->forgetPermissionCache();

        return $user;
    }

    /**
     * Grants the owner every catalog permission, as a system-role sync would.
     */
    protected function ownerWithCatalogAccess(): User
    {
        /** @var User $owner */
        $owner = User::query()->where('is_owner', true)->firstOrFail();

        $owner->forgetPermissionCache();

        return $owner;
    }
}
