<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\AdjustSale;
use App\Modules\Sales\Application\Actions\ChangeSaleLine;
use App\Modules\Sales\Application\Actions\CloseSale;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use App\Modules\Sales\Application\Actions\RotateInvoiceLink;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceShareLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| The customer's digital invoice
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§20, 27–29, 46, 54.
|
| Unauthenticated, read-only, reached by a 256-bit token and nothing else. An
| ALLOW-LIST: a number, a date, the center and branch, the customer's name, the
| lines and the totals. Nothing internal, nothing about staff, no notes, no
| reasons, no ids.
|
*/

function piSeed(): array
{
    return test()->seedBookableCenter();
}

/**
 * @return array{0: Invoice, 1: string}
 */
function piIssue(array $seed, $owner): array
{
    test()->openShift($seed['branch'], $owner);

    $customer = test()->seedCustomer('Sara Ahmed', '0750 123 4567', 'sara@example.test');

    $sale = test()->draftSale($seed['branch'], $owner);
    $line = app(AddSaleLine::class)($sale, $owner, [
        'kind' => 'service',
        'service' => $seed['service']->uuid,
        'note' => 'INTERNAL: customer was rude',
    ]);
    app(ChangeSaleLine::class)->overridePrice($sale, $line->uuid, $owner, 18000, 'SECRET-OVERRIDE-REASON');
    app(AdjustSale::class)->customer($sale, $owner, $customer->uuid);
    app(AdjustSale::class)->add($sale, $owner, AdjustmentType::DiscountFixed, 1000, 'SECRET-DISCOUNT-REASON');

    $issued = app(FinalizeSale::class)($sale, $owner);

    // The secret is handed back once, by the finalize that minted it.
    return [$issued->invoice, (string) $issued->shareToken];
}

/**
 * The invoice API. Phase 15: `public.tenant` resolves the center from its own
 * host, and the `{center}` segment must be that host's slug — the pre-Phase-15
 * host-less `/api/v1/invoices/{publicKey}/…` form no longer resolves.
 */
function piUrl(array $center, string $token): string
{
    $slug = $center['registration']->requested_slug;

    return 'http://'.$slug.'.localhost:8000/api/v1/invoices/'.$slug.'/'.$token;
}

/**
 * The customer's invoice page. Phase 15 publishes it on the center's own host,
 * where the route segment and the host must name the same center.
 */
function piPage(array $center): string
{
    return 'http://'.$center['registration']->requested_slug.'.localhost:8000/i/';
}

it('shows a customer their invoice with no login', function (): void {
    $center = $this->registerCenter();

    [$invoice, $token] = $this->asCenter($center['tenant'], function (): array {
        return piIssue(piSeed(), $this->ownerWithCatalogAccess());
    });

    $response = $this->getJson(piUrl($center, $token))->assertStatus(200);

    expect($response->json('data.invoice.number'))->toBe($invoice->number)
        ->and($response->json('data.invoice.customer_name'))->toBe('Sara Ahmed')
        ->and($response->json('data.invoice.grand_total.amount'))->toBe(17000)
        ->and($response->json('data.invoice.lines.0.name'))->toBe('Haircut');

    $this->get(piPage($center).$token)
        ->assertStatus(200)
        ->assertSee($invoice->number)
        ->assertSee('noindex', false);
});

it('publishes an allow-list and nothing internal', function (): void {
    $center = $this->registerCenter();

    [$invoice, $token] = $this->asCenter($center['tenant'], function (): array {
        return piIssue(piSeed(), $this->ownerWithCatalogAccess());
    });

    $payload = $this->getJson(piUrl($center, $token))->assertStatus(200)->json('data.invoice');
    $flat = json_encode($payload, JSON_THROW_ON_ERROR);

    expect(array_keys($payload))->toBe([
        'number', 'issued_date', 'issued_time',
        'center_name', 'branch_name', 'branch_address', 'branch_phone',
        'customer_name',
        'currency', 'lines', 'adjustments',
        'subtotal', 'discount_total', 'surcharge_total', 'tax_total', 'grand_total',
        'voided', 'voided_date',
        'locale', 'direction',
    ]);

    expect(array_keys($payload['lines'][0]))->toBe(['name', 'variation', 'addons', 'quantity', 'unit_price', 'line_subtotal']);

    foreach ([
        'INTERNAL', 'SECRET-OVERRIDE-REASON', 'SECRET-DISCOUNT-REASON',   // staff notes and reasons
        'sara@example.test', '+964750', '0750',                          // customer contact
        $invoice->uuid, 'sale_id', 'branch_id', 'journey', 'employee',   // internal identity
        'issued_by', 'created_by', 'actor',                              // staff identity
        'original_unit_price', 'price_source', 'cost', 'resource',        // internal pricing and ops
        $token,                                                           // the credential itself
    ] as $secret) {
        expect($flat)->not->toContain($secret);
    }
});

it('answers every unknown, malformed or revoked token with the same 404', function (): void {
    $center = $this->registerCenter();

    [$invoice, $token] = $this->asCenter($center['tenant'], function (): array {
        return piIssue(piSeed(), $this->ownerWithCatalogAccess());
    });

    $this->getJson(piUrl($center, str_repeat('a', 64)))->assertStatus(404);
    $this->getJson(piUrl($center, 'short'))->assertStatus(404);
    // The invoice NUMBER is not a key to anything.
    $this->getJson(piUrl($center, $invoice->number))->assertStatus(404);

    $fresh = $this->asCenter($center['tenant'], fn (): string => app(RotateInvoiceLink::class)($invoice, $this->ownerWithCatalogAccess()));

    $this->getJson(piUrl($center, $token))->assertStatus(404);

    expect($fresh)->not->toBe($token)
        ->and(strlen($fresh))->toBe(64);

    $this->getJson(piUrl($center, $fresh))->assertStatus(200);
});

it('keeps exactly one live link per invoice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        [$invoice] = piIssue(piSeed(), $this->ownerWithCatalogAccess());

        app(RotateInvoiceLink::class)($invoice, $this->ownerWithCatalogAccess());
        app(RotateInvoiceLink::class)($invoice, $this->ownerWithCatalogAccess());

        expect(InvoiceShareLink::query()->where('invoice_id', $invoice->id)->count())->toBe(3)
            ->and(InvoiceShareLink::query()->where('active_invoice_id', $invoice->id)->count())->toBe(1)
            ->and(InvoiceShareLink::query()->where('invoice_id', $invoice->id)->whereNotNull('revoked_at')->count())->toBe(2);
    });
});

it('never resolves one center\'s token under another center\'s key', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    [, $token] = $this->asCenter($alpha['tenant'], function (): array {
        return piIssue(piSeed(), $this->ownerWithCatalogAccess());
    });

    $this->getJson(piUrl($alpha, $token))->assertStatus(200);
    $this->getJson(piUrl($beta, $token))->assertStatus(404);
});

it('shows a voided invoice as void, and stays readable if the center loses POS', function (): void {
    $center = $this->registerCenter();

    [$invoice, $token] = $this->asCenter($center['tenant'], function (): array {
        $owner = $this->ownerWithCatalogAccess();
        $issued = piIssue(piSeed(), $owner);

        app(CloseSale::class)->void($issued[0]->sale()->firstOrFail(), $owner, 'Disputed');

        $this->revokeEntitlement('pos');

        return $issued;
    });

    $payload = $this->getJson(piUrl($center, $token))->assertStatus(200)->json('data.invoice');

    expect($payload['voided'])->toBeTrue()
        ->and($payload['number'])->toBe($invoice->number)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Disputed');
});

it('renders right-to-left for Arabic and Kurdish customers', function (): void {
    $center = $this->registerCenter();

    [, $token] = $this->asCenter($center['tenant'], function (): array {
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

        return piIssue(piSeed(), $this->ownerWithCatalogAccess());
    });

    $this->get(piPage($center).$token.'?locale=ar')
        ->assertStatus(200)
        ->assertSee('dir="rtl"', false)
        ->assertSee('الإجمالي');

    $this->get(piPage($center).$token.'?locale=ckb')
        ->assertStatus(200)
        ->assertSee('dir="rtl"', false)
        ->assertSee('کۆی گشتی');

    $this->get(piPage($center).$token.'?locale=en')
        ->assertStatus(200)
        ->assertSee('dir="ltr"', false);
});

/*
|--------------------------------------------------------------------------
| The secret is never at rest
|--------------------------------------------------------------------------
|
| A link is a bearer credential. The table keeps SHA-256 of it; the plaintext
| exists only in the URL handed back by the call that minted it.
|
*/

it('persists only a digest of every link secret, and writes neither secret anywhere', function (): void {
    $center = $this->registerCenter();

    $lines = [];

    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    [$invoice, $first] = $this->asCenter($center['tenant'], function (): array {
        return piIssue(piSeed(), $this->ownerWithCatalogAccess());
    });

    $second = $this->asCenter($center['tenant'], fn (): string => app(RotateInvoiceLink::class)($invoice, $this->ownerWithCatalogAccess()));

    // Both used the way a customer would: the revoked one and the live one,
    // through the API and the page.
    $this->getJson(piUrl($center, $first))->assertStatus(404);
    $this->getJson(piUrl($center, $second))->assertStatus(200);
    $this->get(piPage($center).$first)->assertStatus(404);
    $this->get(piPage($center).$second)->assertStatus(200);

    expect($first)->toMatch('/^[a-f0-9]{64}$/')
        ->and($second)->toMatch('/^[a-f0-9]{64}$/')
        ->and($second)->not->toBe($first);

    $this->asCenter($center['tenant'], function () use ($invoice, $first, $second): void {
        $links = InvoiceShareLink::query()->where('invoice_id', $invoice->id)->orderBy('id')->get();

        // The rows hold exactly the digests — the lookup key — and no column
        // holds the plaintext.
        expect($links->pluck('token_hash')->all())->toBe([hash('sha256', $first), hash('sha256', $second)])
            ->and(DB::connection('tenant')->getSchemaBuilder()->hasColumn('invoice_share_links', 'token'))->toBeFalse()
            ->and(json_encode($links->first()?->toArray(), JSON_THROW_ON_ERROR))->not->toContain('token_hash');

        // Every row of every table in the center's database: sales, invoices,
        // links, audit_logs, sessions, jobs — whatever exists.
        foreach (DB::connection('tenant')->select('SHOW TABLES') as $row) {
            $table = (string) array_values((array) $row)[0];
            $dump = (string) json_encode(DB::connection('tenant')->table($table)->get(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            // `str_contains` + a message, not `not->toContain($secret, $message)`:
            // the message would be a second needle and the negation would pass on it.
            expect(str_contains($dump, $first))->toBeFalse("revoked secret found in tenant table {$table}")
                ->and(str_contains($dump, $second))->toBeFalse("live secret found in tenant table {$table}");
        }
    });

    foreach (['platform_audit_logs', 'jobs', 'failed_jobs'] as $table) {
        if (DB::connection('control')->getSchemaBuilder()->hasTable($table)) {
            $dump = (string) json_encode(DB::connection('control')->table($table)->get(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            expect($dump)->not->toContain($first)->not->toContain($second);
        }
    }

    foreach ($lines as $line) {
        expect($line)->not->toContain($first)->not->toContain($second);
    }
});

it('keeps the link secret out of the security audit when a link is probed on another center\'s host', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    [, $token] = $this->asCenter($alpha['tenant'], function (): array {
        return piIssue(piSeed(), $this->ownerWithCatalogAccess());
    });

    // Alpha's link, presented on Beta's own host with Alpha's slug in the path.
    // Since Phase 15 `ResolvePublicTenant` answers that with the same 404 as
    // any unknown address — a different answer would map which centers exist —
    // on the API and on the page alike.
    $alphaSlug = $alpha['registration']->requested_slug;
    $betaHost = 'http://'.$beta['registration']->requested_slug.'.localhost:8000';

    $this->getJson($betaHost.'/api/v1/invoices/'.$alphaSlug.'/'.$token)->assertNotFound();
    $this->get($betaHost.'/i/'.$token)->assertNotFound();

    // Whatever was recorded about the probe, the secret is not in it.
    $platform = (string) json_encode(PlatformAuditLog::query()->get(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $tenant = $this->asCenter($beta['tenant'], fn (): string => (string) json_encode(TenantAuditLog::query()->get(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));

    expect(str_contains($platform, $token))->toBeFalse()
        ->and(str_contains($tenant, $token))->toBeFalse();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
