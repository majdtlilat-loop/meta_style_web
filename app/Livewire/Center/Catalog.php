<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\ArchiveService;
use App\Modules\Catalog\Application\Actions\SaveService;
use App\Modules\Catalog\Application\Actions\SaveServiceCategory;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Departments\Application\Actions\SaveDepartment;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Departments, menu categories and services on one screen.
 *
 * Prices are entered in MAJOR units and converted through
 * {@see Money::fromMajorString()} — as a string, never a float. Typing "25.10"
 * into a float and multiplying by 100 gives 2509.999…, which truncates to one
 * cent less than the person typed (docs/10-API-FOUNDATION.md §9).
 */
#[Layout('components.layouts.app')]
final class Catalog extends Component
{
    public string $tab = 'services';

    public string $notice = '';

    // ---- department / category form -------------------------------------

    /** @var array<string, string> */
    public array $groupName = [];

    public string $groupKind = 'department';

    // ---- service form ----------------------------------------------------

    public ?string $editingService = null;

    /** @var array<string, string> */
    public array $serviceName = [];

    /** @var array<string, string> */
    public array $serviceDescription = [];

    public string $price = '0';

    public int $duration = 30;

    public ?string $departmentUuid = null;

    public ?string $categoryUuid = null;

    public bool $serviceActive = true;

    public bool $servicePublic = true;

    public bool $allBranches = true;

    /** @var list<string> */
    public array $branchUuids = [];

    /** @var list<string> */
    public array $employeeUuids = [];

    /** @var list<array{uuid: string|null, name: array<string, string>, price: string, duration: string}> */
    public array $variations = [];

    /**
     * The locales this center has enabled.
     *
     * A plain method rather than a Livewire computed property: the forms need
     * it once per render, and a magic `$this->locales` is invisible to static
     * analysis.
     *
     * @return list<string>
     */
    private function enabledLocales(): array
    {
        return app(TenantLocales::class)->enabled();
    }

    public function addVariation(): void
    {
        // An empty price means "inherit from the service" — not zero. That
        // distinction is the whole of ADR-037 and it has to survive the form.
        $this->variations[] = ['uuid' => null, 'name' => [], 'price' => '', 'duration' => ''];
    }

    public function removeVariation(int $index): void
    {
        unset($this->variations[$index]);

        $this->variations = array_values($this->variations);
    }

    public function saveGroup(SaveDepartment $departments, SaveServiceCategory $categories): void
    {
        $this->validate(['groupName' => ['required', 'array']]);

        try {
            if ($this->groupKind === 'department') {
                $departments(name: $this->groupName, actingUser: $this->actor());
            } else {
                $categories(name: $this->groupName, actingUser: $this->actor());
            }
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();

            return;
        }

        $this->groupName = [];
        $this->notice = __('Saved.');
    }

    public function editService(string $uuid): void
    {
        $service = Service::query()->with(['variations', 'branches', 'eligibleEmployees'])
            ->where('uuid', $uuid)->firstOrFail();

        $this->editingService = $uuid;
        $this->serviceName = $service->name->all();
        $this->serviceDescription = $service->short_description?->all() ?? [];
        $this->price = Money::fromMinor($service->price_minor, Currency::default())->toMajorString();
        $this->duration = $service->duration_minutes;
        $this->departmentUuid = $service->department?->uuid;
        $this->categoryUuid = $service->category?->uuid;
        $this->serviceActive = $service->is_active;
        $this->servicePublic = $service->is_public;
        $this->allBranches = $service->available_at_all_branches;
        $this->branchUuids = $service->branches->pluck('uuid')->all();
        $this->employeeUuids = $service->eligibleEmployees->pluck('uuid')->all();

        $this->variations = $service->variations->map(fn ($v): array => [
            'uuid' => $v->uuid,
            'name' => $v->name->all(),
            'price' => $v->price_minor === null
                ? ''
                : Money::fromMinor($v->price_minor, Currency::default())->toMajorString(),
            'duration' => $v->duration_minutes === null ? '' : (string) $v->duration_minutes,
        ])->values()->all();
    }

    public function saveService(SaveService $save): void
    {
        $this->validate([
            'serviceName' => ['required', 'array'],
            'duration' => ['required', 'integer', 'min:1', 'max:1440'],
            'price' => ['required', 'string'],
        ]);

        $currency = Currency::default();

        try {
            $priceMinor = Money::fromMajorString($this->price, $currency)->minor;
            $variations = $this->variationPayload($currency);
        } catch (InvalidArgumentException $e) {
            $this->addError('price', $e->getMessage());

            return;
        }

        $existing = $this->editingService === null
            ? null
            : Service::query()->where('uuid', $this->editingService)->first();

        try {
            $save(new ServiceInput(
                name: $this->serviceName,
                durationMinutes: $this->duration,
                priceMinor: $priceMinor,
                departmentId: $this->idFor(Department::query(), $this->departmentUuid),
                serviceCategoryId: $this->idFor(ServiceCategory::query(), $this->categoryUuid),
                shortDescription: $this->serviceDescription,
                isActive: $this->serviceActive,
                isPublic: $this->servicePublic,
                availableAtAllBranches: $this->allBranches,
                variations: $variations,
                branchIds: $this->allBranches ? [] : $this->idsFor(Branch::query(), $this->branchUuids),
                employeeIds: $this->idsFor(Employee::query(), $this->employeeUuids),
            ), $this->actor(), $existing);
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            $this->addError('serviceName', $e->getMessage());

            return;
        }

        $this->notice = __('Service saved.');
        $this->resetServiceForm();
    }

    public function archiveService(string $uuid, ArchiveService $archive): void
    {
        try {
            $archive(Service::query()->where('uuid', $uuid)->firstOrFail(), $this->actor());

            $this->notice = __('Service archived.');
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function render(): mixed
    {
        $user = $this->actor();
        $currency = Currency::default();

        return view('livewire.center.catalog', [
            'currency' => $currency,
            'departments' => Department::query()->active()->get(),
            'categories' => ServiceCategory::query()->active()->get(),
            'addons' => ServiceAddon::query()->active()->get(),
            'branches' => Branch::query()->active()->orderBy('sort_order')->get(),
            'employees' => Employee::query()->orderBy('id')->get(),
            'services' => Service::query()
                ->with(['department', 'category', 'variations'])
                ->whereNull('archived_at')
                ->orderBy('sort_order')->orderBy('id')->get(),
            'locales' => $this->enabledLocales(),
            'canManageCatalog' => $user->hasPermission(Permission::ServiceCreate)
                || $user->hasPermission(Permission::ServiceUpdate),
            'canArchive' => $user->hasPermission(Permission::ServiceArchive),
            'canManageGroups' => $user->hasPermission(Permission::DepartmentManage)
                || $user->hasPermission(Permission::CategoryManage),
        ]);
    }

    /**
     * @return list<array{uuid: string|null, name: array<string, string|null>, price_minor: int|null, duration_minutes: int|null, is_active: bool}>
     */
    private function variationPayload(Currency $currency): array
    {
        $payload = [];

        foreach ($this->variations as $variation) {
            $price = trim($variation['price']);
            $duration = trim($variation['duration']);

            $payload[] = [
                'uuid' => $variation['uuid'],
                'name' => $variation['name'],
                // Blank stays null: the variation follows the service.
                'price_minor' => $price === '' ? null : Money::fromMajorString($price, $currency)->minor,
                'duration_minutes' => $duration === '' ? null : (int) $duration,
                'is_active' => true,
            ];
        }

        return $payload;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function idFor(Builder $query, ?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = $query->where('uuid', $uuid)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  list<string>  $uuids
     * @return list<int>
     */
    private function idsFor(Builder $query, array $uuids): array
    {
        /** @var list<int> $ids */
        $ids = $query->whereIn('uuid', $uuids)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $ids;
    }

    private function resetServiceForm(): void
    {
        $this->editingService = null;
        $this->serviceName = [];
        $this->serviceDescription = [];
        $this->price = '0';
        $this->duration = 30;
        $this->departmentUuid = null;
        $this->categoryUuid = null;
        $this->serviceActive = true;
        $this->servicePublic = true;
        $this->allBranches = true;
        $this->branchUuids = [];
        $this->employeeUuids = [];
        $this->variations = [];
    }

    private function actor(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
