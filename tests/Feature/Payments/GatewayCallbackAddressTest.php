<?php

declare(strict_types=1);

use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Payments\Application\Actions\InitiateGatewayPayment;
use App\Modules\Payments\Application\GatewayConnections;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeGatewayProvider;

/*
|--------------------------------------------------------------------------
| Where a provider reports back
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §21 and the Phase 15 note in §59. The webhook route is
| `public.tenant`: it resolves a center only on the center's own host, with
| that host's slug repeated in the path. The callback address a payment is
| started with must therefore be that one — the platform host with the public
| key no longer resolves, and a provider's "paid" would never arrive.
|
*/

it('publishes the callback under the center\'s own host, and a callback sent there settles the payment', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    [$payment, $callbackUrl, $accountUuid] = $this->asCenter($center['tenant'], function () use ($slug): array {
        $this->grantPayments();
        $this->fakeGateway();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $account = $this->gatewayAccount($seed['branch']);
        $invoice = $this->issuedInvoice($seed, $owner);

        // The till's request: on the center's host, which host resolution
        // turned into the `center` URL default (asCenter does the same).
        URL::forceRootUrl(app(PlatformHosts::class)->centerUrl($slug));

        try {
            $url = app(GatewayConnections::class)->callbackUrl($account);
            $payment = app(InitiateGatewayPayment::class)->fromDesk($invoice, $account, $owner, 20000);
        } finally {
            URL::forceRootUrl(null);
        }

        return [$payment, $url, $account->uuid];
    });

    expect(parse_url($callbackUrl, PHP_URL_HOST))->toBe($slug.'.localhost')
        ->and($callbackUrl)->toEndWith('/api/v1/payments/'.$slug.'/gateways/'.$accountUuid.'/webhook');

    // What the provider does with that address: a signed "paid" to exactly it.
    [$body, $headers] = FakeGatewayProvider::callback((string) $payment->provider_payment_reference, 'paid', 20000);

    $this->call('POST', $callbackUrl, [], [], [], $this->transformHeadersToServerVars($headers), $body)->assertStatus(202);

    $this->asCenter($center['tenant'], function () use ($payment): void {
        expect($payment->fresh()?->status)->toBe(PaymentStatus::Succeeded);
    });
});

it('still names the center\'s own host and slug off that host', function (): void {
    $center = $this->registerCenter();
    $slug = (string) $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($center, $slug): void {
        $this->grantPayments();
        $this->fakeGateway();
        $account = $this->gatewayAccount($this->seedBookableCenter()['branch']);

        // No host resolution (an API client off-host, a queued job): no
        // `center` default. The public key alone no longer opens anything
        // since centers answer only on their own host (ADR-076, ADR-097).
        app('url')->defaults(['center' => '']);

        $url = app(GatewayConnections::class)->callbackUrl($account);

        expect($url)->toBe(app(PlatformHosts::class)->centerUrl($slug, '/api/v1/payments/'.$slug.'/gateways/'.$account->uuid.'/webhook'))
            ->and(str_contains($url, $center['tenant']->publicKey))->toBeFalse();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
