<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Livewire\Center\PaymentGateways;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\Actions\ManageGatewayAccount;
use App\Modules\Payments\Application\Actions\ResolveGatewayPayment;
use App\Modules\Payments\Application\PaymentsPresenter;
use App\Modules\Payments\Domain\Data\GatewayCredentials;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use App\Modules\Payments\Infrastructure\Providers\FibProvider;
use App\Modules\Sales\Application\Actions\AddSaleLine;
use App\Modules\Sales\Application\Actions\FinalizeSale;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\Support\FakeGatewayProvider;

/*
|--------------------------------------------------------------------------
| A branch's merchant account
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§18–20, 48, 61, 67.
|
| Credentials go in encrypted and never come out — not through an API, a
| presenter, a log line or the audit trail. Where a provider lives is platform
| configuration, never something a center can type.
|
*/

const GA_SECRET = 'client-secret-value-9f81';

function gaCredentials(): array
{
    return ['client_id' => 'client-id-00001234', 'client_secret' => GA_SECRET, 'webhook_secret' => 'whsec-value-7713'];
}

it('stores credentials encrypted, and never presents, logs or audits them', function (): void {
    $center = $this->registerCenter();

    $lines = [];

    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $this->asCenter($center['tenant'], function () use (&$lines): void {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $account = app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FakeGatewayProvider::CODE, $owner, gaCredentials(), GatewayEnvironment::Sandbox, 'Main desk TestPay');

        $raw = (string) DB::connection('tenant')->table('payment_gateway_accounts')->value('credentials');
        $presented = json_encode(app(PaymentsPresenter::class)->gatewayAccount($account), JSON_THROW_ON_ERROR);
        $audit = json_encode(TenantAuditLog::query()->get()->map->getAttributes()->all(), JSON_THROW_ON_ERROR);

        expect(str_contains($raw, GA_SECRET))->toBeFalse()
            // ...and it is real encryption, not a one-way hash: it decrypts.
            ->and(GatewayAccount::query()->firstOrFail()->credentials['client_secret'] ?? null)->toBe(GA_SECRET)
            ->and(str_contains($presented, GA_SECRET))->toBeFalse()
            ->and(str_contains($presented, 'credentials'))->toBeFalse()
            ->and(json_decode($presented, true)['safe_identifier'])->toBe('••••1234')
            ->and(str_contains($audit, GA_SECRET))->toBeFalse()
            ->and(str_contains($audit, 'whsec-value-7713'))->toBeFalse()
            ->and(json_encode($account->toArray(), JSON_THROW_ON_ERROR))->not->toContain('client_secret')
            ->and(TenantAuditLog::query()->where('action', 'payment_gateway.configured')->count())->toBe(1);

        foreach ($lines as $line) {
            expect(str_contains($line, GA_SECRET))->toBeFalse();
        }
    });
});

it('lets only a gateway manager configure one, and only with online payments', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        expect(fn () => app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FakeGatewayProvider::CODE, $owner, gaCredentials(), GatewayEnvironment::Sandbox, 'Desk'))
            ->toThrow(EntitlementRequired::class);

        $this->grantPayments();

        $cashier = $this->staffWith(SystemRole::Cashier->permissions(), 'cashier@alpha.test');

        expect(SystemRole::Cashier->permissions())->not->toContain(Permission::PaymentGatewayManage);

        expect(fn () => app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FakeGatewayProvider::CODE, $cashier, gaCredentials(), GatewayEnvironment::Sandbox, 'Desk'))
            ->toThrow(AuthorizationException::class);

        expect(fn () => app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FakeGatewayProvider::CODE, $owner, ['client_id' => 'x'], GatewayEnvironment::Sandbox, 'Desk'))
            ->toThrow(PaymentFailed::class, 'Every credential field');

        expect(GatewayAccount::query()->count())->toBe(0);
    });
});

it('cannot configure a provider with no verified adapter, or a FIB environment the platform has not set up', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        foreach (['zaincash', 'qi', 'fastpay'] as $provider) {
            expect(fn () => app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, $provider, $owner, ['client_id' => 'abcdefgh1'], GatewayEnvironment::Sandbox, 'Desk'))
                ->toThrow(PaymentFailed::class, 'not available yet');
        }

        config(['payments.providers.fib.base_urls.live' => null]);

        expect(fn () => app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FibProvider::CODE, $owner, ['client_id' => 'abcdefgh1', 'client_secret' => 's'], GatewayEnvironment::Live, 'Desk'))
            ->toThrow(PaymentFailed::class, 'not available in that environment');

        expect(GatewayAccount::query()->count())->toBe(0);
    });
});

it('keeps a destination out of a center\'s hands: no URL is stored, and the host comes from configuration', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // A center that tries to smuggle a destination in as a "credential".
        $account = app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FibProvider::CODE, $owner, [
            'client_id' => 'client-id-00001234',
            'client_secret' => 'secret',
            'base_url' => 'http://169.254.169.254/latest/meta-data',
        ], GatewayEnvironment::Sandbox, 'FIB desk');

        expect(DB::connection('tenant')->getSchemaBuilder()->hasColumn('payment_gateway_accounts', 'base_url'))->toBeFalse()
            // Only the declared fields are kept; the smuggled one is dropped.
            ->and(array_keys($account->credentials ?? []))->toBe(['client_id', 'client_secret']);

        Http::fake(['*' => Http::response(['access_token' => 't'], 200)]);

        try {
            app(FibProvider::class)->cancelPayment(
                new GatewayCredentials($account->uuid, GatewayEnvironment::Sandbox, ['client_id' => 'a', 'client_secret' => 'b', 'base_url' => 'http://169.254.169.254']),
                'abc-123',
            );
        } catch (Throwable) {
        }

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://fib.stage.fib.iq/'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '169.254'));
    });
});

it('keeps credentials when disabled, and refuses switching environment while a payment is pending', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $account = app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FakeGatewayProvider::CODE, $owner, gaCredentials(), GatewayEnvironment::Sandbox, 'Desk');
        $account = app(ManageGatewayAccount::class)->setEnabled($account, $owner, true);

        app(InitiateGatewayPayment::class)->fromDesk($this->issuedInvoice($seed, $owner), $account, $owner, 20000);

        expect(fn () => app(ManageGatewayAccount::class)->configure($seed['branch']->uuid, FakeGatewayProvider::CODE, $owner, gaCredentials(), GatewayEnvironment::Live, 'Desk'))
            ->toThrow(PaymentFailed::class, 'still pending');

        $disabled = app(ManageGatewayAccount::class)->setEnabled($account, $owner, false);

        expect($disabled->enabled)->toBeFalse()
            ->and(GatewayAccount::query()->firstOrFail()->credentials['client_secret'] ?? null)->toBe(GA_SECRET)
            ->and(TenantAuditLog::query()->where('action', 'payment_gateway.disabled')->count())->toBe(1);
    });
});

it('fails closed, never with an error page, when credentials no longer decrypt after a key rotation', function (): void {
    $center = $this->registerCenter();

    [$token, $payment, $account] = $this->asCenter($center['tenant'], function (): array {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->openShift($seed['branch'], $owner);

        $sale = $this->draftSale($seed['branch'], $owner);
        app(AddSaleLine::class)($sale, $owner, ['kind' => 'service', 'service' => $seed['service']->uuid]);
        $issued = app(FinalizeSale::class)($sale, $owner);

        $account = $this->gatewayAccount($seed['branch']);
        $payment = app(InitiateGatewayPayment::class)->fromDesk($issued->invoice, $account, $owner, 5000);

        // The same credentials, encrypted under a key this application no longer has.
        $foreign = new Encrypter(random_bytes(32), 'AES-256-CBC');
        DB::connection('tenant')->table('payment_gateway_accounts')->update([
            'credentials' => $foreign->encryptString(json_encode(gaCredentials(), JSON_THROW_ON_ERROR)),
        ]);

        return [(string) $issued->shareToken, $payment, $account];
    });

    // The row exactly as it stands with unreadable credentials — enabled, configured.
    $before = $this->asCenter($center['tenant'], fn (): array => (array) DB::connection('tenant')->table('payment_gateway_accounts')->first());

    expect((int) $before['enabled'])->toBe(1)
        ->and($before['configured_at'])->not->toBeNull();

    // The customer's invoice and its payment options still render — without a pay button.
    // The center's own host (Phase 15): `{slug}.<base>/i/{token}`.
    $this->get('http://'.$center['registration']->requested_slug.'.localhost:8000/i/'.$token)->assertOk();
    $this->getJson('http://'.$center['registration']->requested_slug.'.localhost:8000/api/v1/invoices/'.$center['registration']->requested_slug.'/'.$token.'/payment')
        ->assertOk()
        ->assertJsonPath('data.payment.online_options', []);

    // A callback for the payment in flight is refused, not crashed on, and changes nothing.
    [$body, $headers] = FakeGatewayProvider::callback((string) $payment->provider_payment_reference, 'paid', 5000);
    $slug = $center['registration']->requested_slug;
    $this->call('POST', 'http://'.$slug.'.localhost:8000/api/v1/payments/'.$slug.'/gateways/'.$account->uuid.'/webhook', [], [], [], $this->transformHeadersToServerVars($headers), $body)
        ->assertStatus(400);

    $this->asCenter($center['tenant'], function () use ($payment, $before): void {
        $owner = $this->ownerWithCatalogAccess();
        $unreadable = GatewayAccount::query()->firstOrFail();

        expect($unreadable->isConfigured())->toBeFalse()
            ->and($unreadable->readableCredentials())->toBeNull()
            ->and(app(PaymentsPresenter::class)->gatewayAccount($unreadable)['configured'])->toBeFalse()
            ->and($payment->fresh()?->status)->toBe(PaymentStatus::Pending);

        $this->actingAs($owner, 'web');
        Livewire::test(PaymentGateways::class)->assertOk();

        expect(fn () => app(ResolveGatewayPayment::class)->refresh($payment, $owner))
            ->toThrow(PaymentFailed::class, 'not configured');

        // Fail-safe is READ-ONLY: every path above — the public page and its
        // JSON, a callback, the presenter, the settings screen, a staff refresh —
        // left the account row byte-identical. Nothing switched it off, cleared
        // its credentials or touched its configuration.
        expect((array) DB::connection('tenant')->table('payment_gateway_accounts')->first())->toBe($before);

        // A manager entering the credentials again brings the account back.
        app(ManageGatewayAccount::class)->configure($unreadable->branch()->firstOrFail()->uuid, FakeGatewayProvider::CODE, $owner, gaCredentials(), GatewayEnvironment::Sandbox, 'Desk');

        expect(GatewayAccount::query()->firstOrFail()->isConfigured())->toBeTrue();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
