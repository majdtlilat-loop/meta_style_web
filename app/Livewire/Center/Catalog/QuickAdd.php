<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Livewire\Center\Catalog\Concerns\CatalogFeedback;
use App\Modules\Catalog\Application\Actions\SaveService;
use App\Modules\Catalog\Domain\Data\ServiceInput;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * Adds a service in one line — name, minutes, price — into the category the
 * library is showing. Everything else takes its default (every branch, on the
 * menu, bookable online) and can be refined in the drawer afterwards.
 *
 * Same Action as the full form; this is a shorter door, not a second path.
 */
final class QuickAdd extends Component
{
    use CatalogFeedback;

    /** The library's current list: '' (all), 'none' or a category uuid. */
    #[Reactive]
    public string $category = '';

    public string $quickName = '';

    public int|string $quickDuration = 30;

    public string $quickPrice = '';

    public function add(SaveService $save): void
    {
        $this->validate([
            'quickName' => ['required', 'string', 'max:190'],
            'quickDuration' => ['required', 'integer', 'min:1', 'max:1440'],
            'quickPrice' => ['required', 'string', 'max:32'],
        ], [], [
            'quickName' => __('manager_catalog.fields.name'),
            'quickDuration' => __('manager_catalog.fields.duration'),
            'quickPrice' => __('manager_catalog.fields.price'),
        ]);

        try {
            $price = PriceInput::parse($this->quickPrice, Currency::default());
        } catch (InvalidArgumentException $e) {
            $this->addError('quickPrice', $e->getMessage());

            return;
        }

        $category = $this->targetCategory();

        try {
            $save(new ServiceInput(
                name: [app(TenantLocales::class)->default() => $this->quickName],
                durationMinutes: (int) $this->quickDuration,
                priceMinor: $price,
                serviceCategoryId: $category?->id,
            ), $this->actor());
        } catch (AuthorizationException) {
            $this->flash(__('ui.errors.forbidden'), 'danger');

            return;
        } catch (ValidationException $e) {
            $this->addError('quickName', CatalogErrors::first($e));

            return;
        }

        $this->reset(['quickName', 'quickPrice', 'notice']);
        $this->dispatch('catalog-changed', message: __('manager_catalog.notices.created'));
    }

    public function render(): View
    {
        $primary = app(TenantLocales::class)->default();
        $category = $this->targetCategory();

        return view('livewire.center.catalog.quick-add', [
            'currency' => Currency::default()->value,
            'nameDir' => app(LanguageRegistry::class)->direction($primary),
            'nameLang' => $primary,
            'target' => $category instanceof ServiceCategory
                ? app(LibraryPresenter::class)->name($category->name)
                : __('manager_catalog.categories.uncategorised'),
        ]);
    }

    private function targetCategory(): ?ServiceCategory
    {
        if ($this->category === '' || $this->category === 'none') {
            return null;
        }

        return ServiceCategory::query()->where('uuid', $this->category)->whereNull('archived_at')->first();
    }
}
