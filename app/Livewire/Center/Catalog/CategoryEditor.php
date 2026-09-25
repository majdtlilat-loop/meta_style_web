<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog;

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Livewire\Center\Catalog\Concerns\CatalogFeedback;
use App\Modules\Catalog\Application\Actions\SaveServiceCategory;
use App\Modules\Catalog\Application\CatalogQuery;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The category drawer: its name and description in every enabled content
 * language, whether it is on the menu, its one image, and archive.
 *
 * A category is how the MENU is grouped for customers. It routes nothing — a
 * department does that (ADR-037) — so archiving one only leaves its services
 * uncategorised.
 */
final class CategoryEditor extends Component
{
    use CatalogFeedback;

    public bool $open = false;

    #[Locked]
    public ?string $editingCategory = null;

    /** @var array<string, string> */
    public array $categoryName = [];

    /** @var array<string, string> */
    public array $categoryDescription = [];

    public bool $categoryActive = true;

    public bool $categoryPublic = true;

    #[On('catalog-create-category')]
    public function create(): void
    {
        app(CatalogQuery::class)->authorize($this->actor());

        $this->resetForm();
        $this->open = true;
    }

    #[On('catalog-edit-category')]
    public function edit(string $uuid): void
    {
        app(CatalogQuery::class)->authorize($this->actor());

        $category = ServiceCategory::query()->where('uuid', $uuid)->whereNull('archived_at')->first();

        if (! $category instanceof ServiceCategory) {
            $this->flash(__('manager_catalog.errors.not_found'), 'danger');

            return;
        }

        $this->resetForm();
        $this->editingCategory = $category->uuid;
        $this->categoryName = $category->name->all();
        $this->categoryDescription = $category->description?->all() ?? [];
        $this->categoryActive = $category->is_active;
        $this->categoryPublic = $category->is_public;
        $this->open = true;
    }

    public function close(): void
    {
        $this->resetForm();
        $this->open = false;
    }

    public function save(SaveServiceCategory $save): void
    {
        $primary = app(TenantLocales::class)->default();

        $this->validate([
            "categoryName.{$primary}" => ['required', 'string', 'max:190'],
            'categoryName.*' => ['nullable', 'string', 'max:190'],
            'categoryDescription.*' => ['nullable', 'string', 'max:1000'],
        ], [], [
            "categoryName.{$primary}" => __('manager_catalog.fields.name'),
            'categoryName.*' => __('manager_catalog.fields.name'),
            'categoryDescription.*' => __('manager_catalog.fields.description'),
        ]);

        $existing = $this->editingCategory === null
            ? null
            : ServiceCategory::query()->where('uuid', $this->editingCategory)->first();

        try {
            $category = $save(
                name: $this->categoryName,
                actingUser: $this->actor(),
                category: $existing,
                description: $this->categoryDescription,
                isActive: $this->categoryActive,
                isPublic: $this->categoryPublic,
            );
        } catch (AuthorizationException) {
            $this->flash(__('ui.errors.forbidden'), 'danger');

            return;
        } catch (ValidationException $e) {
            $this->addError("categoryName.{$primary}", CatalogErrors::first($e));

            return;
        }

        $this->dispatch('catalog-changed', message: $existing === null
            ? __('manager_catalog.notices.category_created')
            : __('manager_catalog.notices.category_saved'));

        if ($existing === null) {
            // Stay open on the new category so its image can be added now.
            $this->editingCategory = $category->uuid;
            $this->flash(__('manager_catalog.notices.category_created_image'));

            return;
        }

        $this->close();
    }

    public function archive(SaveServiceCategory $save): void
    {
        $category = $this->editingCategory === null
            ? null
            : ServiceCategory::query()->where('uuid', $this->editingCategory)->first();

        if (! $category instanceof ServiceCategory) {
            return;
        }

        $done = $this->attempt(fn () => $save->archive($category, $this->actor()));

        if ($done) {
            $this->dispatch('catalog-changed', message: __('manager_catalog.notices.category_archived'));
            $this->close();
        }
    }

    public function render(): View
    {
        if (! $this->open) {
            return view('livewire.center.catalog.category-editor', ['open' => false]);
        }

        $locales = app(TenantLocales::class);
        $viewer = $this->actor();

        return view('livewire.center.catalog.category-editor', [
            'open' => true,
            'title' => $this->editingCategory === null
                ? __('manager_catalog.category_editor.create_title')
                : __('manager_catalog.category_editor.edit_title', ['name' => TranslatedText::fromArray($this->categoryName)->get(app()->getLocale())]),
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
            'textFields' => [
                ['name' => 'categoryName', 'label' => __('manager_catalog.fields.name'), 'max' => 190, 'required' => true],
                ['name' => 'categoryDescription', 'label' => __('manager_catalog.fields.description'), 'type' => 'textarea', 'rows' => 3, 'max' => 1000, 'counter' => true, 'recommended' => 240],
            ],
            'textValues' => ['categoryName' => $this->categoryName, 'categoryDescription' => $this->categoryDescription],
            'canSave' => $viewer->hasPermission(Permission::CategoryManage),
            'canMedia' => $viewer->hasPermission(Permission::MediaUpload),
        ]);
    }

    private function resetForm(): void
    {
        $this->reset(['editingCategory', 'categoryName', 'categoryDescription', 'categoryActive', 'categoryPublic', 'notice']);
        $this->resetErrorBag();
    }
}
