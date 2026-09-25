<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog\Concerns;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Livewire\Center\Catalog\CatalogErrors;
use App\Livewire\Center\Catalog\LibraryPresenter;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Resources\Domain\Models\ResourceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * What the service drawer needs besides its own state: validation rules, the
 * pick lists and the language tabs. Kept apart so the editor itself reads as
 * the flow — open, change, save.
 *
 * Pick lists show what may be CHOSEN (live departments and categories, active
 * branches, staff and resource types) plus whatever the service already has,
 * so an edit never silently drops a link the list would otherwise hide.
 *
 * @phpstan-require-extends Component
 */
trait ServiceEditorOptions
{
    /**
     * @return array<string, list<string>>
     */
    protected function formRules(string $primary): array
    {
        return [
            "serviceName.{$primary}" => ['required', 'string', 'max:190'],
            'serviceName.*' => ['nullable', 'string', 'max:190'],
            'serviceSummary.*' => ['nullable', 'string', 'max:500'],
            'serviceDescription.*' => ['nullable', 'string', 'max:5000'],
            'duration' => ['required', 'integer', 'min:1', 'max:1440'],
            'price' => ['required', 'string', 'max:32'],
            'branchUuids' => $this->allBranches ? ['array'] : ['required', 'array', 'min:1'],
            "variations.*.name.{$primary}" => ['required', 'string', 'max:190'],
            'variations.*.name.*' => ['nullable', 'string', 'max:190'],
            'variations.*.price' => ['nullable', 'string', 'max:32'],
            'variations.*.duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'requirements.*.quantity' => ['required', 'integer', 'min:1', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function attributeNames(string $primary): array
    {
        return [
            "serviceName.{$primary}" => __('manager_catalog.fields.name'),
            'serviceName.*' => __('manager_catalog.fields.name'),
            'serviceSummary.*' => __('manager_catalog.fields.summary'),
            'serviceDescription.*' => __('manager_catalog.fields.description'),
            'duration' => __('manager_catalog.fields.duration'),
            'price' => __('manager_catalog.fields.price'),
            'branchUuids' => __('manager_catalog.fields.branches'),
            "variations.*.name.{$primary}" => __('manager_catalog.fields.variation_name'),
            'variations.*.name.*' => __('manager_catalog.fields.variation_name'),
            'variations.*.price' => __('manager_catalog.fields.price'),
            'variations.*.duration' => __('manager_catalog.fields.duration'),
            'requirements.*.quantity' => __('manager_catalog.fields.quantity'),
        ];
    }

    /**
     * Everything the open drawer renders, as plain values.
     *
     * @return array<string, mixed>
     */
    protected function options(User $viewer): array
    {
        $tenantLocales = app(TenantLocales::class);
        $registry = app(LanguageRegistry::class);
        $present = app(LibraryPresenter::class);
        $primary = $tenantLocales->default();

        $locales = $tenantLocales->enabled();
        usort($locales, fn (string $a, string $b): int => ($b === $primary) <=> ($a === $primary));

        $name = TranslatedText::fromArray($this->serviceName)->get(app()->getLocale());

        return [
            'title' => $this->editingService === null
                ? __('manager_catalog.editor.create_title')
                : __('manager_catalog.editor.edit_title', ['name' => $name]),
            'locales' => $locales,
            'primaryLocale' => $primary,
            'languages' => array_map(fn (string $code): array => [
                'code' => $code,
                'short' => $registry->shortLabel($code),
                'native' => $registry->nativeName($code),
                'dir' => $registry->direction($code),
                'primary' => $code === $primary,
            ], $locales),
            'textFields' => [
                ['name' => 'serviceName', 'label' => __('manager_catalog.fields.name'), 'max' => 190, 'required' => true],
                ['name' => 'serviceSummary', 'label' => __('manager_catalog.fields.summary'), 'max' => 500, 'counter' => true, 'recommended' => 160],
                ['name' => 'serviceDescription', 'label' => __('manager_catalog.fields.description'), 'type' => 'textarea', 'rows' => 4, 'max' => 5000],
            ],
            'textValues' => [
                'serviceName' => $this->serviceName,
                'serviceSummary' => $this->serviceSummary,
                'serviceDescription' => $this->serviceDescription,
            ],
            'currency' => Currency::default()->value,
            'departments' => $this->pick(
                Department::query()->where(fn (Builder $q) => $q->whereNull('archived_at')->orWhere('uuid', (string) $this->departmentUuid))
                    ->orderBy('sort_order')->orderBy('id')->get(),
                $present,
            ),
            'categories' => $this->pick(
                ServiceCategory::query()->where(fn (Builder $q) => $q->whereNull('archived_at')->orWhere('uuid', (string) $this->categoryUuid))
                    ->orderBy('sort_order')->orderBy('id')->get(),
                $present,
            ),
            'branches' => $this->pick(
                Branch::query()->where(fn (Builder $q) => $q->where(fn (Builder $live) => $live->where('is_active', true)->whereNull('archived_at'))
                    ->orWhereIn('uuid', $this->branchUuids))
                    ->orderBy('sort_order')->orderBy('id')->get(),
                $present,
            ),
            'employees' => Employee::query()
                ->where(fn (Builder $q) => $q->where('status', EmployeeStatus::Active->value)->orWhereIn('uuid', $this->employeeUuids))
                ->orderBy('id')->get()
                ->map(fn (Employee $e): array => [
                    'uuid' => $e->uuid,
                    'name' => $present->name($e->name),
                    'inactive' => $e->status !== EmployeeStatus::Active,
                ])->values()->all(),
            'resourceTypes' => $viewer->hasPermission(Permission::ResourceView)
                ? $this->pick(
                    ResourceType::query()->where(fn (Builder $q) => $q->where(fn (Builder $live) => $live->where('is_active', true)->whereNull('archived_at'))
                        ->orWhereIn('uuid', array_column($this->requirements, 'type')))
                        ->orderBy('sort_order')->orderBy('id')->get(),
                    $present,
                )
                : [],
            'can' => [
                'save' => $viewer->hasPermission($this->editingService === null ? Permission::ServiceCreate : Permission::ServiceUpdate),
                'resourcesView' => $viewer->hasPermission(Permission::ResourceView),
                'resourcesManage' => $viewer->hasPermission(Permission::ResourceManage),
                'media' => $viewer->hasPermission(Permission::MediaUpload),
            ],
            'bookingOwned' => app(Entitlements::class)->enabled('booking'),
        ];
    }

    /**
     * @param  iterable<Model>  $models
     * @return list<array{uuid: string, name: string, archived: bool}>
     */
    private function pick(iterable $models, LibraryPresenter $present): array
    {
        $rows = [];

        foreach ($models as $model) {
            $name = $model->getAttribute('name');
            $rows[] = [
                'uuid' => (string) $model->getAttribute('uuid'),
                'name' => $present->name($name instanceof TranslatedText ? $name : null),
                'archived' => $model->getAttribute('archived_at') !== null,
            ];
        }

        return $rows;
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function idOrError(Builder $query, ?string $uuid, string $field, string $reason): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $id = $query->where('uuid', $uuid)->value('id');

        if (! is_numeric($id)) {
            $this->addError($field, CatalogErrors::message($reason));

            return null;
        }

        return (int) $id;
    }

    /**
     * Tenant-scoped: a uuid from another center finds nothing here.
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $uuids
     * @return list<int>
     */
    private function idsFor(Builder $query, array $uuids): array
    {
        /** @var list<int> $ids */
        $ids = $query->whereIn('uuid', $uuids)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        return $ids;
    }
}
