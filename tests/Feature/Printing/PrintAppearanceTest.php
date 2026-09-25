<?php

declare(strict_types=1);

use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Livewire\Center\Appearance\PrintAppearance as PrintAppearancePage;
use App\Modules\Printing\Application\Actions\UpdatePrintAppearance;
use App\Modules\Printing\Application\PrintAppearance;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Print appearance
|--------------------------------------------------------------------------
|
| How a center's own paper looks — logo, header lines, footer, which details
| print, the document language — stored as tenant settings and applied only
| when a document is RENDERED. The invoice record is immutable and is never
| touched; `printing` owns the paper and `appearance.manage` the change.
|
*/

/**
 * @return array<string, mixed>
 */
function paSession(array $center, $user): array
{
    return [
        StanclTenantResolver::SESSION_KEY => test()->publicKeyOf($center['tenant']),
        Auth::guard('web')->getName() => $user->getAuthIdentifier(),
    ];
}

function paUrl(array $center, string $path): string
{
    return 'http://'.$center['registration']->requested_slug.'.localhost:8000'.$path;
}

function paInvoice($owner): Invoice
{
    $seed = test()->seedBookableCenter();
    test()->openShift($seed['branch'], $owner);
    $customer = test()->seedCustomer('Sara Ahmed', '0750 123 4567');
    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
    app(AdjustSale::class)->customer($sale, $owner, $customer->uuid);

    return app(FinalizeSale::class)($sale, $owner)->invoice;
}

it('saves print appearance with printing and appearance.manage, audited, and refuses markup', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();
        $update = app(UpdatePrintAppearance::class);

        // No printing entitlement yet: the paper is not the center's to style.
        expect(fn () => $update($owner, ['values' => ['logo_size' => 'large']]))->toThrow(EntitlementRequired::class);

        $this->grantPrinting();

        // A fresh Action, as the next request would get: the refused one kept
        // its own per-instance entitlement memo from before the grant.
        $update = app(UpdatePrintAppearance::class);

        $saved = $update($owner, [
            'values' => ['show_customer_name' => false, 'document_language' => 'center', 'receipt_text_size' => 'large'],
            'texts' => ['header_text' => ['en' => "Reg. 12345\nwww.alpha.test"], 'footer_text' => ['en' => 'See you soon!']],
        ]);

        expect($saved->flag('show_customer_name'))->toBeFalse()
            ->and(app(PrintAppearance::class)->get()->text('footer_text', 'en'))->toBe('See you soon!')
            ->and(TenantAuditLog::query()->where('action', 'print.appearance.updated')->firstOrFail()->after['changed'])
            ->toContain('show_customer_name', 'texts.footer_text');

        expect(fn () => $update($owner, ['texts' => ['footer_text' => ['en' => '<img src=x onerror=alert(1)>']]]))->toThrow(AppearanceRejected::class)
            ->and(fn () => $update($owner, ['values' => ['logo_align' => 'justify']]))->toThrow(AppearanceRejected::class)
            ->and(fn () => $update($this->staffWith([Permission::AppearanceView], 'print-viewer@alpha.test'), ['values' => ['logo_size' => 'small']]))
            ->toThrow(AuthorizationException::class);
    });
});

it('applies the appearance when an invoice is printed and never touches the invoice', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    [$invoice, $before] = $this->asCenter($center['tenant'], function (): array {
        $this->grantPrinting();
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'ar');
        $owner = $this->ownerWithCatalogAccess();
        $invoice = paInvoice($owner);

        app(UpdatePrintAppearance::class)($owner, [
            'values' => ['show_customer_name' => false, 'document_language' => 'center'],
            'texts' => [
                'header_text' => ['ar' => 'رقم التسجيل 12345'],
                'footer_text' => ['ar' => 'نراك قريبًا'],
            ],
        ]);

        return [$invoice, $invoice->fresh()->getAttributes()];
    });

    foreach (['80mm', 'a4'] as $format) {
        $html = (string) $this->withSession(paSession($center, $owner))
            ->get(paUrl($center, "/manager/sales/invoices/{$invoice->uuid}/print/{$format}"))
            ->assertOk()
            // The center's primary content language, whoever prints.
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('رقم التسجيل 12345')
            ->assertSee('نراك قريبًا')
            ->assertSee($invoice->number)
            ->getContent();

        expect(str_contains($html, 'Sara Ahmed'))->toBeFalse()
            ->and(str_contains($html, '0750'))->toBeFalse();
    }

    // The immutable record is exactly what it was.
    $after = $this->asCenter($center['tenant'], fn (): array => $invoice->fresh()->getAttributes());
    expect($after)->toBe($before);
});

it('frames a queue ticket with the center appearance and still prints no customer details', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $uuid = $this->asCenter($center['tenant'], function (): string {
        $seed = $this->seedBookableCenter();
        $this->grantQueueEntitlements();
        $this->grantPrinting();
        $owner = $this->ownerWithCatalogAccess();
        $point = $this->seedServicePoint($seed['branch'], 'L2', 'Laser Room 2', prefix: 'L');

        app(UpdatePrintAppearance::class)($owner, [
            'values' => ['ticket_show_service' => false, 'ticket_show_date' => false],
            'texts' => ['ticket_footer' => ['en' => 'Please wait to be called']],
        ]);

        return app(CreateWalkInTicket::class)(
            new WalkInRequest(
                branchUuid: $seed['branch']->uuid,
                serviceUuids: [$seed['service']->uuid],
                name: 'Sara Ahmed',
                phone: '+9647512345678',
                idempotencyToken: (string) Str::uuid(),
            ),
            $owner,
            ['service_point' => $point->uuid],
        )['ticket']->uuid;
    });

    $html = (string) $this->withSession(paSession($center, $owner))
        ->get(paUrl($center, "/manager/queue/tickets/{$uuid}/print"))
        ->assertOk()
        ->assertSee('Please wait to be called')
        ->assertSee('Barbershop Alpha')
        ->getContent();

    expect(str_contains($html, 'Haircut'))->toBeFalse()
        ->and(str_contains($html, 'Sara Ahmed'))->toBeFalse()
        ->and(str_contains($html, '+9647'))->toBeFalse();
});

it('shows the print page only to a center that owns printing, with a real-data preview', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);

        // Without printing: the upgrade state, and no form.
        $this->get("http://{$slug}.localhost:8000/manager/appearance/print")
            ->assertOk()
            ->assertDontSee('wire:click="save"', false);

        $this->grantPrinting();

        foreach (['en' => 'Print appearance', 'ar' => 'مظهر الطباعة', 'ckb' => 'شێوەی چاپ'] as $locale => $title) {
            $html = (string) $this->get("http://{$slug}.localhost:8000/manager/appearance/print?locale={$locale}")
                ->assertOk()
                ->assertSee($title)
                ->assertSee('Barbershop Alpha')
                ->getContent();

            expect(preg_match('/\b(manager_appearance|menu_public)\.[a-z_]+/', strip_tags($html)))->toBe(0);
        }

        Livewire::actingAs($owner)->test(PrintAppearancePage::class)
            ->set('texts.footer_text.en', 'Thank you for visiting Alpha')
            ->set('paper', 'a4')
            ->assertSee('Thank you for visiting Alpha')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        expect(app(PrintAppearance::class)->get()->text('footer_text', 'en'))->toBe('Thank you for visiting Alpha');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
