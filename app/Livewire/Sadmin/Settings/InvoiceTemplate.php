<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Settings;

use App\Kernel\Audit\Actor;
use App\Kernel\Localization\LanguageRegistry;
use App\Livewire\Sadmin\Concerns\AuthorizesPlatform;
use App\Modules\SaasBilling\Application\InvoiceTemplateSettings;
use App\Modules\SaasBilling\Application\SaasBillingDocuments;
use App\Modules\SaasBilling\Domain\InvoiceTemplate as TemplateSchema;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings → Invoice templates: who is billing (the issuer block every new
 * invoice snapshots), the document texts in each language and the layout —
 * all structured values from fixed lists; no HTML, CSS or script.
 *
 * The preview renders the real document template with sample data in any
 * language, before anything is saved. Saving changes presentation only:
 * issued invoices keep their amounts and their snapshotted issuer.
 */
#[Layout('layouts.superadmin.app')]
final class InvoiceTemplate extends Component
{
    use AuthorizesPlatform;

    /** @var array<string, mixed> */
    public array $template = [];

    public string $previewLocale = 'en';

    public function mount(InvoiceTemplateSettings $settings, LanguageRegistry $languages): void
    {
        $this->requirePlatformPermission('platform.billing.manage');
        $this->template = $this->editable($settings->current());
        $this->previewLocale = $languages->supports(app()->getLocale()) ? app()->getLocale() : 'en';
    }

    public function setPreviewLocale(string $locale, LanguageRegistry $languages): void
    {
        $this->previewLocale = $languages->supports($locale) ? $locale : 'en';
    }

    public function save(InvoiceTemplateSettings $settings): void
    {
        $user = $this->requirePlatformPermission('platform.billing.manage');
        try {
            $saved = $settings->save($this->template, Actor::platform($user));
        } catch (DomainException $exception) {
            $this->addError('template', $exception->getMessage());

            return;
        }
        $this->template = $this->editable($saved);
        session()->flash('notice', __('platform_settings.invoice.saved'));
    }

    public function render(InvoiceTemplateSettings $settings, SaasBillingDocuments $documents, TemplateSchema $schema, LanguageRegistry $languages): mixed
    {
        $this->requirePlatformPermission('platform.billing.manage');
        try {
            $draft = $schema->normalize($this->template);
            $problem = null;
        } catch (DomainException $exception) {
            $draft = $settings->current();
            $problem = $exception->getMessage();
        }

        return view('livewire.sadmin.settings.invoice-template', [
            'preview' => $documents->sampleHtml($draft, $this->previewLocale),
            'problem' => $problem,
            'dirty' => $problem === null && $draft !== $this->editable($settings->current()),
            'languages' => collect($languages->supported())->mapWithKeys(fn (string $locale): array => [$locale => $languages->shortLabel($locale)])->all(),
        ])->title(__('platform_settings.tabs.invoices'));
    }

    /**
     * The stored template without keys the form does not edit.
     *
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function editable(array $template): array
    {
        return array_intersect_key($template, TemplateSchema::defaults());
    }
}
