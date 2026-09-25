<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Platform\Identity\Actions\ManagePlatformRoles;
use App\Kernel\Platform\Identity\Actions\ManagePlatformUsers;
use App\Kernel\Platform\Identity\Http\Middleware\EnsurePlatformMfa;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\PlatformHosts;
use App\Livewire\Sadmin\Settings\InvoiceTemplate as InvoiceTemplatePage;
use App\Modules\SaasBilling\Application\Actions\CorrectSaasBilling;
use App\Modules\SaasBilling\Application\Actions\IssueSaasInvoice;
use App\Modules\SaasBilling\Application\Actions\RecordManualSaasPayment;
use App\Modules\SaasBilling\Application\InvoiceTemplateSettings;
use App\Modules\SaasBilling\Application\SaasAccountStatement;
use App\Modules\SaasBilling\Application\SaasBillingDocuments;
use App\Modules\SaasBilling\Domain\InvoiceTemplate;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\StatementPeriod;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| SaaS billing documents: invoice print/PDF, templates, account statement
|--------------------------------------------------------------------------
|
| Documents are rendered from the billing records and never change them. The
| template is structured values only; the issuer is frozen on each invoice
| when it is issued, so editing the template later never rewrites history. The
| statement groups by currency and never adds different currencies together.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->admin = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->admin->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($this->admin, 'platform')->withSession([EnsurePlatformMfa::SESSION_KEY => now()->timestamp]);
    $this->actor = Actor::platform($this->admin);

    $center = $this->registerCenter('Documents Center', 'owner@documents.test');
    $this->tenant = TenantModel::query()->findOrFail($center['tenant']->id);
    $this->subscription = Subscription::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->host = rtrim(app(PlatformHosts::class)->superAdminUrl(), '/');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
    $roles = DB::connection('control')->table('platform_roles')->where('is_system', false)->pluck('id');
    DB::connection('control')->table('platform_role_permissions')->whereIn('role_id', $roles)->delete();
    DB::connection('control')->table('platform_roles')->whereIn('id', $roles)->delete();
});

function issueInvoice(object $test, int $amount, int $discount = 0, ?string $currency = null): SaasInvoice
{
    if ($currency !== null) {
        $test->subscription->forceFill(['currency_snapshot' => $currency])->save();
    }

    return app(IssueSaasInvoice::class)($test->subscription->refresh(), 'Plan', $amount, now()->addDays(14), $test->actor, null, $discount, 'REF-'.$amount);
}

it('prints and downloads an invoice only for billing staff', function (): void {
    $invoice = issueInvoice($this, 50000, 5000);

    $this->get($this->host.'/billing/invoices/'.$invoice->uuid.'/print')
        ->assertOk()
        ->assertSee($invoice->number)
        ->assertSee('dir="ltr"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    $pdf = $this->get($this->host.'/billing/invoices/'.$invoice->uuid.'/pdf');
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $pdf->getContent(), '%PDF'))->toBeTrue();

    // Statement filters are allow-lists: an unknown status, currency or range is dropped, never echoed.
    $this->get($this->host.'/centers/'.$this->tenant->id.'/statement/print?preset=custom&from=2000-01-01&to=2026-09-23&status=%3Cb%3Ex&currency=QQQ')
        ->assertOk()
        ->assertDontSee('QQQ')
        ->assertDontSee('<b>x', false);
    $this->get($this->host.'/centers/00000000-0000-0000-0000-000000000000/statement/print')->assertNotFound();

    // A support-only colleague cannot open billing documents.
    $role = app(ManagePlatformRoles::class)->create(['en' => 'Tickets', 'ar' => 'تذاكر', 'ckb' => 'تیکێت'], ['en' => '', 'ar' => '', 'ckb' => ''], ['platform.support.view', 'platform.support.manage'], $this->admin);
    $support = app(ManagePlatformUsers::class)->invite('Support Only', 'support-docs@example.test', [$role->id], $this->admin);
    $support->forceFill(['mfa_confirmed_at' => now()])->save();
    $this->actingAs($support, 'platform');
    $this->get($this->host.'/billing/invoices/'.$invoice->uuid.'/print')->assertForbidden();
    $this->get($this->host.'/billing/invoices/'.$invoice->uuid.'/pdf')->assertForbidden();
    $this->get($this->host.'/centers/'.$this->tenant->id.'/statement/pdf')->assertForbidden();
});

it('renders invoices in English, Arabic and Kurdish with the right direction, as real PDFs', function (): void {
    $invoice = issueInvoice($this, 50000);
    $documents = app(SaasBillingDocuments::class);

    $en = $documents->invoiceHtml($invoice, 'en');
    $ar = $documents->invoiceHtml($invoice, 'ar');
    $ku = $documents->invoiceHtml($invoice, 'ckb');

    expect($en)->toContain('lang="en"')->toContain('dir="ltr"')->toContain(__('saas_documents.invoice.title', [], 'en'))
        ->and($ar)->toContain('lang="ar"')->toContain('dir="rtl"')->toContain(__('saas_documents.invoice.title', [], 'ar'))
        ->and($ku)->toContain('lang="ckb"')->toContain('dir="rtl"')->toContain(__('saas_documents.invoice.title', [], 'ckb'));
    expect(str_contains($ar, 'saas_documents.'))->toBeFalse('no raw translation keys in Arabic')
        ->and(str_contains($ku, 'saas_documents.'))->toBeFalse('no raw translation keys in Kurdish');

    foreach (['en', 'ar', 'ckb'] as $locale) {
        $pdf = $documents->invoicePdf($invoice, $locale);
        expect(str_starts_with($pdf, '%PDF'))->toBeTrue()
            ->and(preg_match_all('#/Type\s*/Page[^s]#', $pdf))->toBe(1);
    }
});

it('saves the template as structured values only and audits identity changes separately', function (): void {
    $settings = app(InvoiceTemplateSettings::class);
    $template = $settings->current();
    $template['texts']['footer']['en'] = 'Thank you for choosing Meta Style.';
    $template['layout'] = 'modern';
    $settings->save($template, $this->actor);

    expect(app(InvoiceTemplateSettings::class)->current()['texts']['footer']['en'])->toBe('Thank you for choosing Meta Style.')
        ->and(app(InvoiceTemplateSettings::class)->current()['layout'])->toBe('modern')
        ->and(PlatformAuditLog::query()->where('action', 'platform.invoice_template.updated')->exists())->toBeTrue();

    $template = app(InvoiceTemplateSettings::class)->current();
    $template['company']['tax_number'] = 'TAX-42';
    app(InvoiceTemplateSettings::class)->save($template, $this->actor);
    expect(PlatformAuditLog::query()->where('action', 'platform.billing_identity.updated')->exists())->toBeTrue();

    // Markup, a script or a colour that is not on the list is refused.
    $bad = app(InvoiceTemplateSettings::class)->current();
    $bad['texts']['footer']['en'] = '<script>alert(1)</script>';
    expect(fn () => app(InvoiceTemplateSettings::class)->save($bad, $this->actor))->toThrow(DomainException::class);
    $bad = app(InvoiceTemplateSettings::class)->current();
    $bad['accent'] = 'red; background: url(x)';
    expect(fn () => app(InvoiceTemplateSettings::class)->save($bad, $this->actor))->toThrow(DomainException::class);
});

it('previews the template with sample data before saving, in any language', function (): void {
    Livewire::test(InvoiceTemplatePage::class)
        ->set('template.texts.footer.en', 'Preview footer text')
        ->assertSee(__('saas_documents.sample.badge', [], 'en'))
        ->call('setPreviewLocale', 'ar')
        ->assertSet('previewLocale', 'ar');

    $sample = app(SaasBillingDocuments::class)->sampleHtml(InvoiceTemplate::defaults(), 'ckb');
    expect($sample)->toContain('dir="rtl"')->toContain(__('saas_documents.sample.badge', [], 'ckb'))
        ->and(SaasInvoice::query()->count())->toBe(0);
});

it('never rewrites an issued invoice when the template or identity changes later', function (): void {
    $settings = app(InvoiceTemplateSettings::class);
    $template = $settings->current();
    $template['company']['name']['en'] = 'Meta Style Original LLC';
    $settings->save($template, $this->actor);

    $invoice = issueInvoice($this, 70000, 7000);
    $before = $invoice->only(['subtotal_minor', 'discount_minor', 'total_minor', 'paid_minor', 'status', 'issued_at']);
    expect($invoice->issuer_snapshot['name']['en'])->toBe('Meta Style Original LLC');

    $template = app(InvoiceTemplateSettings::class)->current();
    $template['company']['name']['en'] = 'Meta Style Renamed LLC';
    $template['layout'] = 'compact';
    app(InvoiceTemplateSettings::class)->save($template, $this->actor);

    $invoice->refresh();
    $html = app(SaasBillingDocuments::class)->invoiceHtml($invoice, 'en');
    expect($invoice->only(['subtotal_minor', 'discount_minor', 'total_minor', 'paid_minor', 'status', 'issued_at']))->toEqual($before)
        ->and($html)->toContain('Meta Style Original LLC')
        ->and(str_contains($html, 'Meta Style Renamed LLC'))->toBeFalse('an issued invoice keeps the issuer it was issued with');

    // A new invoice carries the new identity.
    $next = issueInvoice($this, 10000);
    expect($next->issuer_snapshot['name']['en'])->toBe('Meta Style Renamed LLC');
});

it('builds the statement per currency, with reversals and voids, and never mixes currencies', function (): void {
    $iqd = issueInvoice($this, 50000, 5000, 'IQD');
    $payment = app(RecordManualSaasPayment::class)($iqd, 20000, 'cash', now(), $this->actor, 'CASH-1');
    app(RecordManualSaasPayment::class)($iqd, 10000, 'bank_transfer', now(), $this->actor, 'TRX-2');
    app(CorrectSaasBilling::class)->reversePayment($payment, $this->actor, 'Counted twice');
    $voided = issueInvoice($this, 30000, 0, 'IQD');
    app(CorrectSaasBilling::class)->voidInvoice($voided, $this->actor, 'Issued by mistake');
    $usd = issueInvoice($this, 2500, 0, 'USD');

    $period = StatementPeriod::resolve('this_year', null, null, 'Asia/Baghdad');
    $statement = app(SaasAccountStatement::class)->build((string) $this->tenant->id, $period->from, $period->to);
    $groups = collect($statement['currencies'])->keyBy('currency');

    expect($groups->keys()->sort()->values()->all())->toBe(['IQD', 'USD']);
    $iqdGroup = $groups['IQD'];
    // Invoiced 45,000 + 30,000; the void credits the 30,000 back; 30,000 paid, 20,000 of it reversed.
    expect($iqdGroup['totals'])->toBe(['invoiced' => 75000, 'discounts' => 5000, 'voided' => 30000, 'paid' => 30000, 'reversed' => 20000])
        ->and($iqdGroup['opening'])->toBe(0)
        ->and($iqdGroup['closing'])->toBe(75000 - 30000 - 30000 + 20000)
        ->and(end($iqdGroup['lines'])['balance'])->toBe($iqdGroup['closing']);
    expect($groups['USD']['totals']['invoiced'])->toBe(2500)
        ->and($groups['USD']['closing'])->toBe(2500);

    // A currency filter shows only that currency; a status filter drops the balances.
    $only = app(SaasAccountStatement::class)->build((string) $this->tenant->id, $period->from, $period->to, ['currency' => 'USD']);
    expect(array_column($only['currencies'], 'currency'))->toBe(['USD']);
    $filtered = app(SaasAccountStatement::class)->build((string) $this->tenant->id, $period->from, $period->to, ['method' => 'cash']);
    expect($filtered['filtered'])->toBeTrue()
        ->and($filtered['currencies'][0]['opening'])->toBeNull()
        ->and($filtered['currencies'][0]['closing'])->toBeNull();

    // Printing the statement changes nothing.
    $snapshot = SaasInvoice::query()->orderBy('id')->get(['total_minor', 'paid_minor', 'status'])->toArray();
    foreach (['en', 'ar', 'ckb'] as $locale) {
        $html = app(SaasBillingDocuments::class)->statementHtml($this->tenant, $period->from, $period->to, [], $locale);
        expect($html)->toContain('IQD')->toContain('USD');
        expect(str_starts_with(app(SaasBillingDocuments::class)->statementPdf($this->tenant, $period->from, $period->to, [], $locale), '%PDF'))->toBeTrue();
    }
    expect(SaasInvoice::query()->orderBy('id')->get(['total_minor', 'paid_minor', 'status'])->toArray())->toBe($snapshot);
    expect($usd->refresh()->total_minor)->toBe(2500);
});

it('resolves statement periods and refuses unbounded or reversed ranges', function (): void {
    $custom = StatementPeriod::resolve('custom', '2026-01-01', '2026-03-31', 'Asia/Baghdad');
    expect($custom->preset)->toBe('custom')
        ->and($custom->from->format('Y-m-d'))->toBe('2026-01-01')
        ->and($custom->to->format('Y-m-d H:i'))->toBe('2026-03-31 23:59');

    expect(StatementPeriod::resolve('custom', '2026-05-01', '2026-01-01', 'Asia/Baghdad')->preset)->toBe('this_month')
        ->and(StatementPeriod::resolve('custom', '2000-01-01', '2026-01-01', 'Asia/Baghdad')->preset)->toBe('this_month')
        ->and(StatementPeriod::resolve('bogus', null, null, 'Asia/Baghdad')->preset)->toBe('this_month');
});
