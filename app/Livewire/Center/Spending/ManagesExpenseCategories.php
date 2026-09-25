<?php

declare(strict_types=1);

namespace App\Livewire\Center\Spending;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Finance\Application\Actions\ManageExpenseCategory;
use App\Modules\Finance\Application\FinanceQuery;

/**
 * The expense categories drawer: add, rename per content language, archive
 * and bring back. Categories are the center's vocabulary, so one list for all
 * branches (docs/20-FINANCE.md §38).
 *
 * Names are edited one field per ENABLED content language. A language the
 * center has switched off keeps its stored text: renaming merges into what is
 * there rather than replacing it (docs/07-LOCALIZATION.md).
 */
trait ManagesExpenseCategories
{
    public bool $showCategories = false;

    public bool $showArchivedCategories = false;

    /** Quick add: the name in the center's primary content language. */
    public string $categoryName = '';

    public string $editingCategory = '';

    /** @var array<string, string> locale => name */
    public array $categoryNames = [];

    /**
     * Opens the categories panel in place of the expense form, so two side
     * panels never stack; what was typed in the form stays for when it reopens.
     */
    public function openCategories(): void
    {
        $this->showForm = false;
        $this->showCategories = true;
    }

    public function addCategory(ManageExpenseCategory $manage, TenantLocales $locales): void
    {
        $this->attempt(function () use ($manage, $locales): void {
            $manage->save([$locales->default() => trim($this->categoryName)], $this->user());

            $this->reset(['categoryName']);
            $this->saved = (string) __('Category added.');
        });
    }

    public function editCategory(string $uuid, FinanceQuery $query, TenantLocales $locales): void
    {
        $this->attempt(function () use ($uuid, $query, $locales): void {
            $category = $query->category($uuid, $this->user());
            $names = [];

            foreach ($locales->enabled() as $locale) {
                $names[$locale] = (string) ($category->name->in($locale) ?? '');
            }

            $this->editingCategory = $category->uuid;
            $this->categoryNames = $names;
        });
    }

    public function cancelCategoryEdit(): void
    {
        $this->reset(['editingCategory', 'categoryNames']);
    }

    public function saveCategory(ManageExpenseCategory $manage, FinanceQuery $query, TenantLocales $locales): void
    {
        $this->attempt(function () use ($manage, $query, $locales): void {
            $category = $query->category($this->editingCategory, $this->user());

            // Stored text in a language that is switched off stays as it is.
            $names = $category->name->all();

            foreach ($locales->enabled() as $locale) {
                $names[$locale] = trim((string) ($this->categoryNames[$locale] ?? ''));
            }

            $manage->save($names, $this->user(), $category, $category->sort_order);

            $this->reset(['editingCategory', 'categoryNames']);
            $this->saved = (string) __('manager_finance.expenses.category_saved');
        });
    }

    public function archiveCategory(string $uuid, ManageExpenseCategory $manage, FinanceQuery $query): void
    {
        $this->attempt(function () use ($uuid, $manage, $query): void {
            $manage->archive($query->category($uuid, $this->user()), $this->user());
            $this->saved = (string) __('Category archived. Past expenses keep it.');
        });
    }

    public function restoreCategory(string $uuid, ManageExpenseCategory $manage, FinanceQuery $query): void
    {
        $this->attempt(function () use ($uuid, $manage, $query): void {
            $manage->unarchive($query->category($uuid, $this->user()), $this->user());
            $this->saved = (string) __('manager_finance.expenses.category_restored');
        });
    }

    /**
     * The per-language fields of the category being renamed.
     *
     * @return list<array{locale: string, label: string, dir: string, primary: bool}>
     */
    protected function categoryLocales(TenantLocales $locales, LanguageRegistry $languages): array
    {
        $primary = $locales->default();

        return array_map(static fn (string $locale): array => [
            'locale' => $locale,
            'label' => $languages->shortLabel($locale).' · '.$languages->nativeName($locale),
            'dir' => $languages->direction($locale),
            'primary' => $locale === $primary,
        ], $locales->enabled());
    }
}
