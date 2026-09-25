<?php

declare(strict_types=1);

use App\Modules\Payments\Application\PaymentProviderRegistry;

/*
|--------------------------------------------------------------------------
| The providers the platform actually offers
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§15–20.
|
| FIB has a real adapter built from its published documentation. ZainCash, Qi
| and FastPay have none until theirs can be verified: registered as explicitly
| unavailable, never as a guess that "probably works". And no provider asks a
| center where to send requests.
|
*/

it('registers only verified adapters as available, and the rest as explicitly unavailable', function (): void {
    $providers = collect(app(PaymentProviderRegistry::class)->all())
        ->mapWithKeys(fn ($provider): array => [$provider->code() => [
            'available' => $provider->capabilities()->available,
            'refunds' => $provider->capabilities()->refunds,
        ]]);

    expect($providers->all())->toBe([
        'fib' => ['available' => true, 'refunds' => false],
        'zaincash' => ['available' => false, 'refunds' => false],
        'qi' => ['available' => false, 'refunds' => false],
        'fastpay' => ['available' => false, 'refunds' => false],
    ]);
});

it('asks no center for a URL, a host or an endpoint', function (): void {
    foreach (app(PaymentProviderRegistry::class)->all() as $provider) {
        foreach ($provider->credentialFields() as $field) {
            expect(preg_match('/url|uri|host|endpoint|domain|base|callback|redirect/i', $field))->toBe(0, "{$provider->code()} asks for {$field}");
        }
    }
});

it('configures every provider host as https, or not at all', function (): void {
    foreach ((array) config('payments.providers') as $code => $settings) {
        foreach ((array) ($settings['base_urls'] ?? []) as $environment => $url) {
            if ($url === null || $url === '') {
                continue;
            }

            expect(str_starts_with((string) $url, 'https://'))->toBeTrue("{$code} {$environment} is not https");
        }
    }

    // FIB's sandbox is the documented stage host; its live host is the operator's to set.
    expect(config('payments.providers.fib.base_urls.sandbox'))->toBe('https://fib.stage.fib.iq');
});
