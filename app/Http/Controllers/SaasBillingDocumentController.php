<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\Kernel\Platform\Settings\PlatformPreferences;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\SaasBilling\Application\SaasAccountStatement;
use App\Modules\SaasBilling\Application\SaasBillingDocuments;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\StatementPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Print views and PDFs of SaaS billing documents, for platform staff holding
 * `platform.billing.manage` (the route enforces it). Read-only: rendering a
 * document never changes an invoice, a payment or a template.
 */
final class SaasBillingDocumentController extends Controller
{
    public function __construct(
        private readonly SaasBillingDocuments $documents,
        private readonly LanguageRegistry $languages,
        private readonly PlatformCurrencies $currencies,
    ) {}

    public function invoice(Request $request, string $invoice): Response
    {
        $model = $this->findInvoice($invoice);
        $locale = $this->documents->locale($this->string($request, 'lang'));

        return $this->html($this->documents->invoiceHtml($model, $locale, false, null, [
            'toolbar' => $this->toolbar('superadmin.billing.invoice.print', 'superadmin.billing.invoice.pdf', ['invoice' => $model->uuid], $locale),
            'autoPrint' => $request->boolean('print'),
        ]));
    }

    public function invoicePdf(Request $request, string $invoice): Response
    {
        $model = $this->findInvoice($invoice);
        $locale = $this->documents->locale($this->string($request, 'lang'));

        return $this->pdf($this->documents->invoicePdf($model, $locale), $model->number);
    }

    public function statement(Request $request, string $tenant, PlatformPreferences $preferences): Response
    {
        $center = $this->findTenant($tenant);
        $locale = $this->documents->locale($this->string($request, 'lang'));
        $period = $this->period($request, $preferences);
        $filters = $this->filters($request);

        return $this->html($this->documents->statementHtml($center, $period->from, $period->to, $filters, $locale, false, [
            'toolbar' => $this->toolbar('superadmin.centers.statement.print', 'superadmin.centers.statement.pdf', ['tenant' => $center->id] + $period->query() + array_filter($filters), $locale),
            'autoPrint' => $request->boolean('print'),
        ]));
    }

    public function statementPdf(Request $request, string $tenant, PlatformPreferences $preferences): Response
    {
        $center = $this->findTenant($tenant);
        $locale = $this->documents->locale($this->string($request, 'lang'));
        $period = $this->period($request, $preferences);

        return $this->pdf(
            $this->documents->statementPdf($center, $period->from, $period->to, $this->filters($request), $locale),
            'statement-'.Str::slug((string) ($center->slug ?? $center->name)).'-'.$period->from->format('Ymd').'-'.$period->to->format('Ymd'),
        );
    }

    private function findInvoice(string $uuid): SaasInvoice
    {
        /** @var SaasInvoice|null $invoice */
        $invoice = Str::isUuid($uuid) ? SaasInvoice::query()->where('uuid', $uuid)->first() : null;
        abort_if($invoice === null, 404);

        return $invoice;
    }

    private function findTenant(string $id): TenantModel
    {
        /** @var TenantModel|null $tenant */
        $tenant = TenantModel::query()->find($id);
        abort_if($tenant === null, 404);

        return $tenant;
    }

    private function period(Request $request, PlatformPreferences $preferences): StatementPeriod
    {
        return StatementPeriod::resolve(
            $this->string($request, 'preset'),
            $this->string($request, 'from'),
            $this->string($request, 'to'),
            $preferences->general()['default_timezone'],
        );
    }

    /** @return array{status: string|null, method: string|null, currency: string|null} */
    private function filters(Request $request): array
    {
        $status = $this->string($request, 'status');
        $method = $this->string($request, 'method');
        $currency = $this->string($request, 'currency');
        $currency = $currency !== null && preg_match('/^[A-Za-z]{3}$/', $currency) === 1 ? mb_strtoupper($currency) : null;

        return [
            'status' => in_array($status, SaasAccountStatement::STATUSES, true) ? $status : null,
            'method' => in_array($method, SaasAccountStatement::METHODS, true) ? $method : null,
            // Only a currency the platform knows; anything else is not echoed onto the document.
            'currency' => $currency !== null && $this->currencies->find($currency) !== null ? $currency : null,
        ];
    }

    private function string(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{pdf: string, languages: array<string, string>}
     */
    private function toolbar(string $printRoute, string $pdfRoute, array $parameters, string $locale): array
    {
        $languages = [];
        foreach ($this->languages->supported() as $code) {
            $languages[$code] = route($printRoute, $parameters + ['lang' => $code]);
        }

        return ['pdf' => route($pdfRoute, $parameters + ['lang' => $locale]), 'languages' => $languages];
    }

    private function html(string $html): Response
    {
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function pdf(string $bytes, string $name): Response
    {
        $file = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name).'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
