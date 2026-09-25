<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\SalesAccess;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| Printing invoices
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§21–26.
|
| `pos` publishes and shows invoices; `printing` owns the PAPER. The seeded trial
| plan owns `pos` and not `printing`, so "a center with POS but without Printing
| still sees its invoice digitally" is a real baseline here, not a vacuous one.
|
| The 80mm receipt and the A4 page are LAYOUTS of the same allow-listed view
| model, printed by the browser. No PDF library, no print agent.
|
*/

function ipSeed(): array
{
    return test()->seedBookableCenter();
}

function ipIssue(array $seed, $owner): Invoice
{
    test()->openShift($seed['branch'], $owner);

    $customer = test()->seedCustomer('Sara Ahmed', '0750 123 4567');

    $sale = test()->draftSale($seed['branch'], $owner);
    app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid, 'addons' => [$seed['addon']->uuid]]);
    app(AdjustSale::class)->customer($sale, $owner, $customer->uuid);

    return app(FinalizeSale::class)($sale, $owner)->invoice;
}

/**
 * The print page lives on the center's own host under /manager (Phase 15);
 * the tenant is resolved from that host, the staff member from the session.
 */
function ipUrl(array $center, string $path): string
{
    return 'http://'.$center['registration']->requested_slug.'.localhost:8000'.$path;
}

/**
 * @return array<string, mixed>
 */
function ipSession(array $center, $user): array
{
    return [
        StanclTenantResolver::SESSION_KEY => test()->publicKeyOf($center['tenant']),
        Auth::guard('web')->getName() => $user->getAuthIdentifier(),
    ];
}

it('shows the invoice digitally to a center with POS but no Printing, and refuses the paper', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $invoice = $this->asCenter($center['tenant'], function (): Invoice {
        // The baseline, pinned rather than assumed.
        expect(app(Entitlements::class)->enabled('pos'))->toBeTrue()
            ->and(app(Entitlements::class)->enabled('printing'))->toBeFalse();

        return ipIssue(ipSeed(), $this->ownerWithCatalogAccess());
    });

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/invoices/{$invoice->uuid}")
        ->assertStatus(200)
        ->assertJsonPath('data.invoice.document.number', $invoice->number);

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/invoices/{$invoice->uuid}/printable?format=80mm")
        ->assertStatus(403);

    $this->withSession(ipSession($center, $owner))
        ->get(ipUrl($center, "/manager/sales/invoices/{$invoice->uuid}/print/80mm"))
        ->assertStatus(403);
});

it('prints an 80mm receipt and an A4 page once the center buys Printing', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $invoice = $this->asCenter($center['tenant'], function (): Invoice {
        $this->grantPrinting();

        return ipIssue(ipSeed(), $this->ownerWithCatalogAccess());
    });

    $receipt = $this->withSession(ipSession($center, $owner))
        ->get(ipUrl($center, "/manager/sales/invoices/{$invoice->uuid}/print/80mm"))
        ->assertOk()
        ->assertSee('size: 80mm auto', false)
        ->assertSee($invoice->number)
        ->assertSee('Haircut')
        ->assertSee('Hair Wash')
        ->assertSee('25,000 IQD');

    $this->withSession(ipSession($center, $owner))
        ->get(ipUrl($center, "/manager/sales/invoices/{$invoice->uuid}/print/a4"))
        ->assertOk()
        ->assertSee('size: A4', false)
        ->assertSee($invoice->number);

    // No customer contact on the paper, ever.
    expect($receipt->getContent())->not->toContain('+964750')
        ->and($receipt->getContent())->not->toContain('0750');

    $this->withSession(ipSession($center, $owner))
        ->get(ipUrl($center, "/manager/sales/invoices/{$invoice->uuid}/print/pdf"))
        ->assertStatus(404);
});

it('needs the print permission as well as the entitlement', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPrinting();
        $seed = ipSeed();
        $invoice = ipIssue($seed, $this->ownerWithCatalogAccess());

        $viewer = $this->staffWith([Permission::SaleView], 'viewer@alpha.test');

        expect(fn () => app(SalesAccess::class)->ensurePrinting($viewer, $invoice->branch_id))
            ->toThrow(AuthorizationException::class);

        $this->revokeEntitlement('printing');

        expect(fn () => app(SalesAccess::class)->ensurePrinting($this->ownerWithCatalogAccess(), $invoice->branch_id))
            ->toThrow(EntitlementRequired::class);
    });
});

it('prints right-to-left in Arabic and Kurdish through the same template', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $invoice = $this->asCenter($center['tenant'], function (): Invoice {
        $this->grantPrinting();
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

        return ipIssue(ipSeed(), $this->ownerWithCatalogAccess());
    });

    foreach (['80mm', 'a4'] as $format) {
        $this->withSession(ipSession($center, $owner))
            ->get(ipUrl($center, "/manager/sales/invoices/{$invoice->uuid}/print/{$format}?locale=ar"))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ar"', false)
            ->assertSee('قص شعر')
            ->assertSee('د.ع');

        $this->withSession(ipSession($center, $owner))
            ->get(ipUrl($center, "/manager/sales/invoices/{$invoice->uuid}/print/{$format}?locale=ckb"))
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('ژمارەی پسوولە');
    }
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
