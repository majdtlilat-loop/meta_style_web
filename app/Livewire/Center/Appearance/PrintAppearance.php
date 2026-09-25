<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Appearance\Appearance;
use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Livewire\Center\Appearance\Concerns\EditsAppearanceDocument;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Printing\Application\Actions\UpdatePrintAppearance;
use App\Modules\Printing\Application\PrintAppearance as PrintSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Manager → Appearance → Print.
 *
 * How the center's own paper looks: the 80mm receipt, the A4 invoice and the
 * queue ticket. Stored as tenant settings and applied only when a document is
 * rendered — an invoice is never rewritten. Belongs to `printing`: without it
 * the page shows the upgrade state and loads nothing. `appearance.view` to
 * open, `appearance.manage` + `printing` to save (UpdatePrintAppearance).
 *
 * Meta Style's own SaaS invoice template is a platform setting elsewhere and
 * has nothing to do with this page.
 */
#[Layout('components.layouts.app')]
final class PrintAppearance extends Component
{
    use EditsAppearanceDocument;
    use RequiresFeature;

    /** Which paper the preview shows: receipt, a4 or ticket. */
    public string $paper = 'receipt';

    public string $previewLocale = '';

    public function mount(PrintSettings $print, TenantLocales $locales): void
    {
        $this->viewer();
        $this->loadDocument($print->get());
        $this->previewLocale = $print->documentLocale(app()->getLocale());

        if (! in_array($this->previewLocale, $locales->enabled(), true)) {
            $this->previewLocale = $locales->default();
        }
    }

    public function save(UpdatePrintAppearance $update): void
    {
        $this->resetErrorBag();

        try {
            $this->loadDocument($update($this->viewer(), $this->input()));
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

        $this->say(__('manager_appearance.print.saved'));
    }

    public function restoreDefaults(PrintSettings $print): void
    {
        $this->viewer(Permission::AppearanceManage);
        $this->resetErrorBag();
        $this->loadDocument(Appearance::defaults($print->schema()));
        $this->say(__('manager_appearance.defaults_restored'));
    }

    public function render(PrintSettings $print, TenantLocales $locales, LanguageRegistry $languages, TenantContext $tenants): View
    {
        $user = $this->viewer();

        if ($locked = $this->lockedView('printing')) {
            return $locked;
        }

        $schema = $print->schema();
        $paper = in_array($this->paper, ['receipt', 'a4', 'ticket'], true) ? $this->paper : 'receipt';
        $locale = in_array($this->previewLocale, $locales->enabled(), true) ? $this->previewLocale : $locales->default();
        $draft = Appearance::fromStored($schema, $this->input(), $languages->supported());
        $sample = $print->view($locale, $draft);

        $text = $this->textFields($schema, [
            'header_text' => ['label' => __('manager_appearance.print.texts.header_text'), 'type' => 'textarea', 'rows' => 3],
            'footer_text' => ['label' => __('manager_appearance.print.texts.footer_text'), 'type' => 'textarea', 'rows' => 3],
            'ticket_footer' => ['label' => __('manager_appearance.print.texts.ticket_footer'), 'type' => 'textarea', 'rows' => 2],
        ]);

        $choices = [];

        foreach ($schema->choices as $key => $choice) {
            foreach ($choice['choices'] as $value) {
                $choices[$key][$value] = __('manager_appearance.print.choices.'.$key.'.'.$value);
            }
        }

        return view('livewire.center.appearance.print', [
            'canManage' => $user->hasPermission(Permission::AppearanceManage),
            'contentLocales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
            'previewLocales' => $this->previewLocales(),
            'textFields' => $text['fields'],
            'textValues' => $text['values'],
            'choices' => $choices,
            'hasLogo' => $sample['logo'] !== null || ! ($this->values['show_logo'] ?? true),
            'brandUrl' => Route::has('center.appearance.brand') ? route('center.appearance.brand') : null,
            'paper' => $paper,
            'sample' => $sample,
            'sampleDoc' => $this->sampleDocument($tenants->require()->name, $locale, $sample),
            'sampleLocale' => $locale,
            'sampleDirection' => $languages->direction($locale),
        ])->title(__('manager_appearance.print.title'));
    }

    /**
     * The header lines the preview prints: the center's real name and its
     * main branch's real details — no invented customer, item or amount.
     *
     * @param  array<string, mixed>  $sample
     * @return array{center_name: string, branch_name: string|null, branch_address: string|null, branch_phone: string|null}
     */
    private function sampleDocument(string $centerName, string $locale, array $sample): array
    {
        $branch = Branch::main() ?? Branch::query()->active()->orderBy('sort_order')->first();

        return [
            'center_name' => $centerName,
            'branch_name' => $branch?->name->get($locale),
            'branch_address' => ($sample['show_branch_address'] ?? true) ? $branch?->address?->get($locale) : null,
            'branch_phone' => ($sample['show_branch_phone'] ?? true) ? $branch?->getAttribute('phone') : null,
        ];
    }
}
