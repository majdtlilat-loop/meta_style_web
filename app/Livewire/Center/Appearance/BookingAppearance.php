<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Appearance\Appearance;
use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Appearance\Concerns\EditsAppearanceDocument;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Menu\Application\Actions\SavePageAppearance;
use App\Modules\Menu\Application\PublicBrand;
use App\Modules\Menu\Application\PublicPageAppearance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manager → Appearance → Booking page.
 *
 * How the existing public booking page looks: brand or own colours, the
 * header, how the steps read, the button, and the customer-facing copy in
 * every enabled language. The booking FLOW is untouched — this is the frame
 * around it. `appearance.view` to open, `appearance.manage` + the `booking`
 * entitlement to save (both enforced in SavePageAppearance).
 */
#[Layout('components.layouts.app')]
final class BookingAppearance extends Component
{
    use EditsAppearanceDocument;
    use RequiresFeature;

    public function mount(PublicPageAppearance $pages): void
    {
        $this->viewer();
        $this->loadDocument($pages->get('booking'));
    }

    public function save(SavePageAppearance $save): void
    {
        $this->resetErrorBag();

        try {
            $this->loadDocument($save($this->viewer(), 'booking', $this->input()));
        } catch (AuthorizationException) {
            $this->fail(__('manager_appearance.errors.forbidden'));

            return;
        } catch (EntitlementRequired) {
            $this->fail(__('manager_appearance.errors.locked'));

            return;
        } catch (AppearanceRejected $e) {
            $this->rejected($e);

            return;
        }

        $this->say(__('manager_appearance.booking.saved'));
    }

    public function preview(PublicPageAppearance $pages): void
    {
        $this->viewer();
        $this->stashPreview('booking', $pages->schema('booking'), 'booking-preview');
    }

    public function restoreDefaults(PublicPageAppearance $pages): void
    {
        $this->viewer(Permission::AppearanceManage);
        $this->resetErrorBag();
        $this->loadDocument(Appearance::defaults($pages->schema('booking')));
        $this->say(__('manager_appearance.defaults_restored'));
    }

    public function render(PublicPageAppearance $pages, TenantLocales $locales, PublicBrand $brand, LanguageRegistry $languages): View
    {
        $user = $this->viewer();

        if ($locked = $this->lockedView('booking')) {
            return $locked;
        }

        $text = $this->textFields($pages->schema('booking'), [
            'title' => ['label' => __('manager_appearance.booking.texts.title')],
            'intro' => ['label' => __('manager_appearance.booking.texts.intro'), 'type' => 'textarea', 'rows' => 3],
            'cta_label' => ['label' => __('manager_appearance.booking.texts.cta_label')],
            'confirmation_message' => ['label' => __('manager_appearance.booking.texts.confirmation_message'), 'type' => 'textarea', 'rows' => 2],
        ]);

        return view('livewire.center.appearance.booking', [
            'canManage' => $user->hasPermission(Permission::AppearanceManage),
            'contentLocales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
            'previewLocales' => $this->previewLocales(),
            'textFields' => $text['fields'],
            'textValues' => $text['values'],
            'brandAvailable' => $brand->resolve() !== null,
            'brandUrl' => Route::has('center.appearance.brand') ? route('center.appearance.brand') : null,
            'policiesUrl' => $user->hasPermission(Permission::SettingsView) ? route('center.settings', ['tab' => 'policies']) : null,
            'publicUrl' => route('menu.book'),
            'choices' => $this->choiceRows($pages),
            // The miniature beside the form, from what is on screen — through
            // the lenient reader, so a half-typed colour never reaches a style.
            'sketch' => $pages->booking(app()->getLocale(), Appearance::fromStored($pages->schema('booking'), $this->input(), $languages->supported())),
        ])->title(__('manager_appearance.booking.title'));
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function choiceRows(PublicPageAppearance $pages): array
    {
        $rows = [];

        foreach ($pages->schema('booking')->choices as $key => $choice) {
            foreach ($choice['choices'] as $value) {
                $rows[$key][$value] = __('manager_appearance.booking.choices.'.$key.'.'.$value);
            }
        }

        return $rows;
    }
}
