<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog;

use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Livewire\Center\Catalog\Concerns\CatalogFeedback;
use App\Livewire\Center\Catalog\Concerns\EditsResourceRequirements;
use App\Livewire\Center\Catalog\Concerns\ServiceEditorOptions;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Application\Actions\SaveService;
use App\Modules\Catalog\Application\CatalogQuery;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The service drawer: create or edit one service, all of it in one save.
 *
 * The form loads EVERY field it sends back — descriptions, online booking,
 * position — so saving an edit can no longer reset what it did not show. The
 * service and its resource requirements are written in ONE tenant transaction
 * by their own Actions; Catalog does not know Resources exists.
 *
 * A saved variation is never removed from here, only switched off: bookings,
 * invoices and packages refer to it (SaveService deactivates, never deletes).
 */
final class ServiceEditor extends Component
{
    use CatalogFeedback;
    use EditsResourceRequirements;
    use ServiceEditorOptions;

    public bool $open = false;

    #[Locked]
    public ?string $editingService = null;

    /** @var array<string, string> */
    public array $serviceName = [];

    /** @var array<string, string> */
    public array $serviceSummary = [];

    /** @var array<string, string> */
    public array $serviceDescription = [];

    public string $price = '';

    public int|string $duration = 30;

    public ?string $departmentUuid = null;

    public ?string $categoryUuid = null;

    public bool $serviceActive = true;

    public bool $servicePublic = true;

    public bool $serviceOnline = true;

    public bool $allBranches = true;

    /** @var list<string> */
    public array $branchUuids = [];

    /** @var list<string> */
    public array $employeeUuids = [];

    /** @var list<array{key: string, uuid: string|null, name: array<string, string>, price: string, duration: string, active: bool}> */
    public array $variations = [];

    #[On('catalog-create-service')]
    public function create(?string $category = null): void
    {
        app(CatalogQuery::class)->authorize($this->actor());

        $this->resetForm();

        if ($category !== null && ServiceCategory::query()->where('uuid', $category)->whereNull('archived_at')->exists()) {
            $this->categoryUuid = $category;
        }

        $this->open = true;
    }

    #[On('catalog-edit-service')]
    public function edit(string $uuid): void
    {
        app(CatalogQuery::class)->authorize($this->actor());

        $service = Service::query()->with(['variations', 'branches', 'eligibleEmployees', 'category', 'department'])
            ->where('uuid', $uuid)->first();

        if (! $service instanceof Service) {
            $this->flash(__('manager_catalog.errors.not_found'), 'danger');

            return;
        }

        $this->resetForm();
        $this->load($service);
        $this->open = true;
    }

    public function close(): void
    {
        $this->resetForm();
        $this->open = false;
    }

    public function addVariation(): void
    {
        // A blank price or duration means "follow the service" — not zero.
        // That distinction is the whole of ADR-037 and it survives the form.
        $this->variations[] = ['key' => 'new-'.Str::lower(Str::random(8)), 'uuid' => null, 'name' => [], 'price' => '', 'duration' => '', 'active' => true];
    }

    public function removeVariation(int $index): void
    {
        if (! isset($this->variations[$index])) {
            return;
        }

        if ($this->variations[$index]['uuid'] !== null) {
            $this->variations[$index]['active'] = false;

            return;
        }

        unset($this->variations[$index]);
        $this->variations = array_values($this->variations);
    }

    public function moveVariation(string $key, int $toIndex): void
    {
        $keys = array_column($this->variations, 'key');
        $from = array_search($key, $keys, true);

        if ($from === false) {
            return;
        }

        $row = array_splice($this->variations, $from, 1);
        array_splice($this->variations, max(0, min($toIndex, count($this->variations))), 0, $row);
    }

    public function moveVariationBy(string $key, int $delta): void
    {
        $from = array_search($key, array_column($this->variations, 'key'), true);

        if ($from !== false) {
            $this->moveVariation($key, $from + ($delta <=> 0));
        }
    }

    public function saveService(): void
    {
        $user = $this->actor();
        $primary = app(TenantLocales::class)->default();

        $this->resetErrorBag();
        $this->validate($this->formRules($primary), [], $this->attributeNames($primary));

        $currency = Currency::default();
        $priceMinor = $this->money($this->price, $currency, 'price');
        $variations = [];

        foreach ($this->variations as $index => $variation) {
            $variations[] = [
                'uuid' => $variation['uuid'],
                'name' => $variation['name'],
                'price_minor' => trim($variation['price']) === '' ? null : $this->money($variation['price'], $currency, "variations.{$index}.price"),
                'duration_minutes' => trim((string) $variation['duration']) === '' ? null : (int) $variation['duration'],
                'is_active' => $variation['active'],
            ];
        }

        $departmentId = $this->idOrError(Department::query(), $this->departmentUuid, 'departmentUuid', 'department');
        $categoryId = $this->idOrError(ServiceCategory::query(), $this->categoryUuid, 'categoryUuid', 'category');

        if ($priceMinor === null || $this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $existing = $this->editingService === null ? null : Service::query()->where('uuid', $this->editingService)->first();

        if ($this->editingService !== null && ! $existing instanceof Service) {
            $this->flash(__('manager_catalog.errors.not_found'), 'danger');

            return;
        }

        $input = new ServiceInput(
            name: $this->serviceName,
            durationMinutes: (int) $this->duration,
            priceMinor: $priceMinor,
            departmentId: $departmentId,
            serviceCategoryId: $categoryId,
            shortDescription: $this->serviceSummary,
            description: $this->serviceDescription,
            isActive: $this->serviceActive,
            isPublic: $this->servicePublic,
            isOnlineBookable: $this->serviceOnline,
            availableAtAllBranches: $this->allBranches,
            variations: $variations,
            branchIds: $this->allBranches ? [] : $this->idsFor(Branch::query(), $this->branchUuids),
            employeeIds: $this->idsFor(Employee::query(), $this->employeeUuids),
        );

        try {
            $saved = DB::connection('tenant')->transaction(fn (): Service => $this->persist($input, $user, $existing));
        } catch (AuthorizationException) {
            $this->flash(__('ui.errors.forbidden'), 'danger');

            return;
        } catch (ValidationException $e) {
            $this->report($e, $primary);

            return;
        }

        $this->dispatch('catalog-changed', message: $existing === null ? __('manager_catalog.notices.created') : __('manager_catalog.notices.saved'));

        if ($existing === null) {
            // Stay open on the new service so its photos can be added now.
            $this->resetForm();
            $this->load($saved->load(['variations', 'branches', 'eligibleEmployees']));
            $this->flash(__('manager_catalog.notices.created_add_photos'));

            return;
        }

        $this->close();
    }

    public function render(): View
    {
        if (! $this->open) {
            return view('livewire.center.catalog.service-editor', ['open' => false]);
        }

        return view('livewire.center.catalog.service-editor', ['open' => true, ...$this->options($this->actor())]);
    }

    private function persist(ServiceInput $input, User $user, ?Service $existing): Service
    {
        $service = app(SaveService::class)($input, $user, $existing);
        $this->saveRequirements($service, $user);

        return $service;
    }

    private function load(Service $service): void
    {
        $currency = Currency::default();

        $this->editingService = $service->uuid;
        $this->serviceName = $service->name->all();
        $this->serviceSummary = $service->short_description?->all() ?? [];
        $this->serviceDescription = $service->description?->all() ?? [];
        $this->price = PriceInput::format($service->price_minor, $currency);
        $this->duration = $service->duration_minutes;
        $this->departmentUuid = $service->department?->uuid;
        $this->categoryUuid = $service->category?->uuid;
        $this->serviceActive = $service->is_active;
        $this->servicePublic = $service->is_public;
        $this->serviceOnline = $service->is_online_bookable;
        $this->allBranches = $service->available_at_all_branches;
        $this->branchUuids = $service->branches->pluck('uuid')->values()->all();
        $this->employeeUuids = $service->eligibleEmployees->pluck('uuid')->values()->all();

        $this->variations = $service->variations->map(fn (ServiceVariation $v): array => [
            'key' => $v->uuid,
            'uuid' => $v->uuid,
            'name' => $v->name->all(),
            'price' => $v->price_minor === null ? '' : PriceInput::format($v->price_minor, $currency),
            'duration' => $v->duration_minutes === null ? '' : (string) $v->duration_minutes,
            'active' => $v->is_active,
        ])->values()->all();

        $this->loadRequirements($service, $this->actor());
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingService', 'serviceName', 'serviceSummary', 'serviceDescription', 'price', 'duration',
            'departmentUuid', 'categoryUuid', 'serviceActive', 'servicePublic', 'serviceOnline', 'allBranches',
            'branchUuids', 'employeeUuids', 'variations', 'requirements', 'requirementsLoaded', 'notice',
        ]);
        $this->resetErrorBag();
    }

    private function money(string $typed, Currency $currency, string $field): ?int
    {
        try {
            return PriceInput::parse($typed, $currency);
        } catch (InvalidArgumentException $e) {
            $this->addError($field, $e->getMessage());

            return null;
        }
    }

    /**
     * Puts each refusal next to the field it is about, in the viewer's language.
     */
    private function report(ValidationException $e, string $primary): void
    {
        foreach (CatalogErrors::all($e) as $key => $message) {
            $field = CatalogErrors::editorField($key, $primary);

            $field === null ? $this->flash($message, 'danger') : $this->addError($field, $message);
        }

        if ($this->notice === '') {
            $this->flash(__('ui.errors.summary'), 'danger');
        }
    }
}
