<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Application;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Platform\Branding\PlatformBranding;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\SaasBilling\Domain\InvoiceTemplate;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\Models\SaasPayment;
use App\Modules\SaasBilling\Infrastructure\Pdf\PdfRenderer;
use Closure;
use Illuminate\Support\Carbon;

/**
 * SaaS billing documents: the invoice and the center account statement, as a
 * print page or a PDF, in English, Arabic or Kurdish.
 *
 * One HTML template per document serves both outputs, so what is printed and
 * what is downloaded are the same document. Money comes only from the billing
 * records, formatted per currency; the template contributes presentation and,
 * for invoices issued before the issuer snapshot existed, the issuer block.
 */
final class SaasBillingDocuments
{
    public function __construct(
        private readonly InvoiceTemplateSettings $templates,
        private readonly SaasAccountStatement $statements,
        private readonly PlatformCurrencies $currencies,
        private readonly LanguageRegistry $languages,
        private readonly PdfRenderer $pdf,
        private readonly PlatformBranding $branding,
    ) {}

    public function locale(?string $requested): string
    {
        return is_string($requested) && $this->languages->supports($requested) ? $requested : app()->getLocale();
    }

    /**
     * @param  array<string, mixed>|null  $template
     * @param  array<string, mixed>  $extra  screen-only view data (toolbar, auto print)
     */
    public function invoiceHtml(SaasInvoice $invoice, string $locale, bool $forPdf = false, ?array $template = null, array $extra = []): string
    {
        return $this->inLocale($locale, fn (): string => view('documents.saas.invoice', $this->invoiceData($invoice, $locale, $forPdf, $template) + $extra)->render());
    }

    public function invoicePdf(SaasInvoice $invoice, string $locale): string
    {
        $template = $this->templates->current();

        return $this->pdf->render($this->invoiceHtml($invoice, $locale, true), $this->languages->direction($locale), (string) $template['paper'], $invoice->number);
    }

    /**
     * The template applied to an illustrative invoice — for the settings
     * preview, so nobody needs a real invoice to see a layout.
     *
     * @param  array<string, mixed>  $template
     */
    public function sampleHtml(array $template, string $locale): string
    {
        $invoice = new SaasInvoice;
        $invoice->forceFill([
            'uuid' => 'sample', 'number' => 'MS-'.now()->format('Ym').'-SAMPLE', 'status' => 'partially_paid', 'currency' => $this->currencies->defaultCode(),
            'subtotal_minor' => 0, 'discount_minor' => 0, 'total_minor' => 0, 'paid_minor' => 0, 'billing_period' => 'monthly',
            'issued_at' => now(), 'due_at' => now()->addDays(14), 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(),
            'reference' => 'PO-0001', 'notes' => null, 'issuer_snapshot' => null, 'plan_name_snapshot' => null,
        ]);

        return $this->inLocale($locale, fn (): string => view('documents.saas.invoice', $this->invoiceData($invoice, $locale, false, $template, true))->render());
    }

    /**
     * @param  array{status?: string|null, method?: string|null, currency?: string|null}  $filters
     * @param  array<string, mixed>  $extra  screen-only view data (toolbar, auto print)
     */
    public function statementHtml(TenantModel $tenant, Carbon $from, Carbon $to, array $filters, string $locale, bool $forPdf = false, array $extra = []): string
    {
        return $this->inLocale($locale, fn (): string => view('documents.saas.statement', $this->statementData($tenant, $from, $to, $filters, $locale, $forPdf) + $extra)->render());
    }

    /**
     * @param  array{status?: string|null, method?: string|null, currency?: string|null}  $filters
     */
    public function statementPdf(TenantModel $tenant, Carbon $from, Carbon $to, array $filters, string $locale): string
    {
        $template = $this->templates->current();

        return $this->pdf->render($this->statementHtml($tenant, $from, $to, $filters, $locale, true), $this->languages->direction($locale), (string) $template['paper'], $tenant->name);
    }

    /**
     * @param  array<string, mixed>|null  $template
     * @return array<string, mixed>
     */
    private function invoiceData(SaasInvoice $invoice, string $locale, bool $forPdf, ?array $template = null, bool $sample = false): array
    {
        $template ??= $this->templates->current();
        $currency = mb_strtoupper($invoice->currency);
        $money = fn (int $minor): string => $this->currencies->format($minor, $currency, $locale);
        $tenant = $sample ? null : TenantModel::query()->find($invoice->tenant_id);

        if ($sample) {
            [$subtotal, $discount, $paid] = [$this->sampleMinor(50000, $currency), $this->sampleMinor(5000, $currency), $this->sampleMinor(20000, $currency)];
            $payments = [['date' => $this->date(now(), $locale), 'method' => __('saas_documents.methods.bank_transfer'), 'reference' => 'TRX-0001', 'amount' => $money($paid), 'reversed' => false]];
            $planName = __('saas_documents.sample.plan');
            $centerName = __('saas_documents.sample.center');
            $contact = ['name' => __('saas_documents.sample.contact'), 'email' => 'owner@example.com', 'phone' => '+964 750 000 0000'];
        } else {
            [$subtotal, $discount, $paid] = [$invoice->subtotal_minor, $invoice->discount_minor, $invoice->paid_minor];
            $payments = SaasPayment::query()->where('invoice_id', $invoice->id)->orderBy('received_at')->get()->map(fn (SaasPayment $payment): array => [
                'date' => $this->date($payment->received_at, $locale),
                'method' => __('saas_documents.methods.'.$payment->method),
                'reference' => $payment->reference,
                'amount' => $money($payment->amount_minor),
                'reversed' => $payment->reversed_at !== null,
            ])->all();
            $planName = $this->pick($invoice->plan_name_snapshot, $locale);
            $centerName = (string) ($tenant->name ?? '—');
            $contact = ['name' => $tenant?->contact_name, 'email' => $tenant?->contact_email, 'phone' => $tenant?->contact_phone];
        }
        $total = $sample ? $subtotal - $discount : $invoice->total_minor;
        $status = $sample ? 'partially_paid' : ($invoice->isOverdue() ? 'overdue' : $invoice->status);
        // Who is billing: frozen on the invoice when it was issued. Invoices
        // issued before the snapshot existed show the current identity.
        $issuer = is_array($invoice->issuer_snapshot) ? InvoiceTemplate::hydrate(['company' => $invoice->issuer_snapshot])['company'] : $template['company'];

        return [
            'doc' => $this->documentFrame($template, $locale, $forPdf, __('saas_documents.invoice.title').' '.$invoice->number),
            'issuer' => $this->issuerView($issuer, $locale),
            'sample' => $sample,
            'invoice' => [
                'uuid' => $invoice->uuid,
                'number' => $invoice->number,
                'status' => $status,
                'status_label' => __('saas_documents.statuses.'.$status),
                'issued' => $this->date($invoice->issued_at, $locale),
                'due' => $this->date($invoice->due_at, $locale),
                'period' => $invoice->period_start && $invoice->period_end ? $this->date($invoice->period_start, $locale).' – '.$this->date($invoice->period_end, $locale) : null,
                'cycle' => in_array($invoice->billing_period, ['monthly', 'yearly'], true) ? __('saas_documents.cycles.'.$invoice->billing_period) : null,
                'currency' => $currency,
                'plan' => $planName,
                'reference' => $invoice->reference,
                'notes' => $invoice->notes,
                'subtotal' => $money($subtotal),
                'discount' => $discount > 0 ? $money($discount) : null,
                'total' => $money($total),
                'paid' => $money($paid),
                'balance' => $money(max(0, $total - $paid)),
                'void' => $status === 'void',
                'payments' => $payments,
            ],
            'center' => ['name' => $centerName, 'contact' => array_filter($contact)],
        ];
    }

    /**
     * @param  array{status?: string|null, method?: string|null, currency?: string|null}  $filters
     * @return array<string, mixed>
     */
    private function statementData(TenantModel $tenant, Carbon $from, Carbon $to, array $filters, string $locale, bool $forPdf): array
    {
        $template = $this->templates->current();
        $statement = $this->statements->build((string) $tenant->id, $from, $to, $filters);

        $groups = [];
        foreach ($statement['currencies'] as $group) {
            $money = fn (?int $minor): ?string => $minor === null ? null : $this->currencies->format($minor, $group['currency'], $locale);
            $groups[] = [
                'currency' => $group['currency'],
                'opening' => $money($group['opening']),
                'closing' => $money($group['closing']),
                'totals' => array_map($money, $group['totals']),
                'lines' => array_map(fn (array $line): array => [
                    'date' => $this->date($line['at'], $locale),
                    'type' => __('saas_documents.statement.types.'.$line['type']),
                    'kind' => $line['type'],
                    'reference' => $line['reference'],
                    'invoice' => $line['invoice_number'],
                    'description' => $this->describe($line, $locale),
                    'debit' => $line['debit'] > 0 ? $money($line['debit']) : null,
                    'credit' => $line['credit'] > 0 ? $money($line['credit']) : null,
                    'balance' => $money($line['balance'] ?? null),
                ], $group['lines']),
            ];
        }

        return [
            'doc' => $this->documentFrame($template, $locale, $forPdf, __('saas_documents.statement.title').' · '.$tenant->name),
            'issuer' => $this->issuerView($template['company'], $locale),
            'center' => ['name' => (string) $tenant->name, 'contact' => array_filter(['name' => $tenant->contact_name, 'email' => $tenant->contact_email, 'phone' => $tenant->contact_phone])],
            'statement' => [
                'from' => $this->date($statement['from'], $locale),
                'to' => $this->date($statement['to'], $locale),
                'generated' => $this->date(now(), $locale),
                'filtered' => $statement['filtered'],
                'filters' => array_filter([
                    'status' => in_array($filters['status'] ?? null, SaasAccountStatement::STATUSES, true) ? __('saas_documents.statuses.'.$filters['status']) : null,
                    'method' => in_array($filters['method'] ?? null, SaasAccountStatement::METHODS, true) ? __('saas_documents.methods.'.$filters['method']) : null,
                    'currency' => is_string($filters['currency'] ?? null) && $filters['currency'] !== '' ? mb_strtoupper($filters['currency']) : null,
                ]),
                'groups' => $groups,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function documentFrame(array $template, string $locale, bool $forPdf, string $title): array
    {
        // The one platform logo (Settings → Branding), light variant: a
        // document is always printed on white.
        $logo = $this->branding->logo('light');

        return [
            'lang' => $locale,
            'dir' => $this->languages->direction($locale),
            'title' => $title,
            'pdf' => $forPdf,
            'layout' => (string) $template['layout'],
            'accent' => InvoiceTemplate::ACCENTS[$template['accent']] ?? InvoiceTemplate::ACCENTS['rose'],
            'paper' => (string) $template['paper'],
            'logo' => [
                'src' => $forPdf ? $logo['path'] : $logo['url'],
                'height' => InvoiceTemplate::LOGO_SIZES[$template['logo_size']] ?? 52,
                'align' => (string) $template['logo_align'],
            ],
            'show' => $template['show'],
            'texts' => array_map(fn (mixed $value): string => $this->pick($value, $locale), $template['texts']),
        ];
    }

    /**
     * @param  array<string, mixed>  $company
     * @return array<string, string>
     */
    private function issuerView(array $company, string $locale): array
    {
        return [
            'name' => $this->pick($company['name'] ?? [], $locale) ?: 'Meta Style',
            'address' => $this->pick($company['address'] ?? [], $locale),
            'phone' => (string) ($company['phone'] ?? ''),
            'email' => (string) ($company['email'] ?? ''),
            'website' => (string) ($company['website'] ?? ''),
            'tax_number' => (string) ($company['tax_number'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $line */
    private function describe(array $line, string $locale): string
    {
        $description = $line['description'];

        return match ($line['type']) {
            'invoice' => implode(' · ', array_filter([
                $this->pick($description['plan'] ?? null, $locale),
                in_array($description['cycle'] ?? null, ['monthly', 'yearly'], true) ? __('saas_documents.cycles.'.$description['cycle']) : null,
                ($description['period_start'] ?? null) && ($description['period_end'] ?? null) ? $this->date($description['period_start'], $locale).' – '.$this->date($description['period_end'], $locale) : null,
                $line['discount'] > 0 ? __('saas_documents.statement.discount_included', ['amount' => $this->currencies->format($line['discount'], $line['currency'], $locale)]) : null,
            ])),
            'payment', 'partial_payment' => __('saas_documents.methods.'.($description['method'] ?? 'other')),
            'reversal', 'void' => (string) ($description['reason'] ?? ''),
            default => '',
        };
    }

    private function pick(mixed $value, string $locale): string
    {
        if (! is_array($value)) {
            return is_string($value) ? $value : '';
        }

        foreach ([$locale, 'en'] as $key) {
            if (is_string($value[$key] ?? null) && $value[$key] !== '') {
                return $value[$key];
            }
        }

        return '';
    }

    private function date(mixed $value, string $locale): string
    {
        if ($value === null) {
            return '—';
        }

        return Carbon::parse($value)->locale($locale)->translatedFormat('j F Y');
    }

    /** A sample amount in whole units of the currency, whatever its decimals. */
    private function sampleMinor(int $whole, string $currency): int
    {
        return $whole * (10 ** $this->currencies->decimals($currency));
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $render
     * @return T
     */
    private function inLocale(string $locale, Closure $render): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            return $render();
        } finally {
            app()->setLocale($previous);
        }
    }
}
