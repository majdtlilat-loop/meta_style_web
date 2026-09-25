<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Appearance\Appearance;
use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Appearance\Concerns\EditsAppearanceDocument;
use App\Modules\Menu\Application\Actions\SavePageAppearance;
use App\Modules\Menu\Application\PublicBrand;
use App\Modules\Menu\Application\PublicPageAppearance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manager → Appearance → Cart page.
 *
 * Styles the cart and checkout pages a customer can reach today. There is no
 * cart backend yet (online checkout is a later phase), so the page never shows
 * a fake cart: what the center sets here is its heading, its empty-state copy,
 * its button, colours and layout. `appearance.view` to open,
 * `appearance.manage` to save (SavePageAppearance).
 */
#[Layout('components.layouts.app')]
final class CartAppearance extends Component
{
    use EditsAppearanceDocument;

    public function mount(PublicPageAppearance $pages): void
    {
        $this->viewer();
        $this->loadDocument($pages->get('cart'));
    }

    public function save(SavePageAppearance $save): void
    {
        $this->resetErrorBag();

        try {
            $this->loadDocument($save($this->viewer(), 'cart', $this->input()));
        } catch (AuthorizationException) {
            $this->fail(__('manager_appearance.errors.forbidden'));

            return;
        } catch (AppearanceRejected $e) {
            $this->rejected($e);

            return;
        }

        $this->say(__('manager_appearance.cart.saved'));
    }

    public function preview(PublicPageAppearance $pages): void
    {
        $this->viewer();
        $this->stashPreview('cart', $pages->schema('cart'), 'cart-preview');
    }

    public function restoreDefaults(PublicPageAppearance $pages): void
    {
        $this->viewer(Permission::AppearanceManage);
        $this->resetErrorBag();
        $this->loadDocument(Appearance::defaults($pages->schema('cart')));
        $this->say(__('manager_appearance.defaults_restored'));
    }

    public function render(PublicPageAppearance $pages, TenantLocales $locales, PublicBrand $brand, LanguageRegistry $languages): View
    {
        $user = $this->viewer();
        $schema = $pages->schema('cart');

        $text = $this->textFields($schema, [
            'heading' => ['label' => __('manager_appearance.cart.texts.heading')],
            'intro' => ['label' => __('manager_appearance.cart.texts.intro'), 'type' => 'textarea', 'rows' => 2],
            'empty_title' => ['label' => __('manager_appearance.cart.texts.empty_title')],
            'empty_body' => ['label' => __('manager_appearance.cart.texts.empty_body'), 'type' => 'textarea', 'rows' => 3],
            'cta_label' => ['label' => __('manager_appearance.cart.texts.cta_label')],
            'checkout_heading' => ['label' => __('manager_appearance.cart.texts.checkout_heading')],
            'checkout_body' => ['label' => __('manager_appearance.cart.texts.checkout_body'), 'type' => 'textarea', 'rows' => 3],
        ]);

        $choices = [];

        foreach ($schema->choices as $key => $choice) {
            foreach ($choice['choices'] as $value) {
                $choices[$key][$value] = __('manager_appearance.cart.choices.'.$key.'.'.$value);
            }
        }

        return view('livewire.center.appearance.cart', [
            'canManage' => $user->hasPermission(Permission::AppearanceManage),
            'contentLocales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
            'previewLocales' => $this->previewLocales(),
            'textFields' => $text['fields'],
            'textValues' => $text['values'],
            'brandAvailable' => $brand->resolve() !== null,
            'brandUrl' => Route::has('center.appearance.brand') ? route('center.appearance.brand') : null,
            'publicUrl' => route('center.cart'),
            'choices' => $choices,
            'sketch' => $pages->cart(app()->getLocale(), Appearance::fromStored($schema, $this->input(), $languages->supported())),
        ])->title(__('manager_appearance.cart.title'));
    }
}
