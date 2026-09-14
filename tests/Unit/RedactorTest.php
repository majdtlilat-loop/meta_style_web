<?php

declare(strict_types=1);

use App\Kernel\Audit\Redactor;

/*
|--------------------------------------------------------------------------
| Audit redaction
|--------------------------------------------------------------------------
|
| docs/08-AUDIT-SECURITY.md §4, §9. Meta Style must never record passwords,
| tokens, merchant secrets, card data, CVV, PIN or OTP — including in the audit
| log itself.
|
*/

it('redacts sensitive keys', function (string $key): void {
    $result = (new Redactor)->redact([$key => 'super-secret-value']);

    expect($result[$key])->toBe(Redactor::PLACEHOLDER)
        ->and($result[$key])->not->toContain('super-secret');
})->with([
    'password', 'PASSWORD', 'db_password', 'user_password',
    'api_key', 'apiKey', 'access_token', 'refresh_token',
    'secret', 'client_secret', 'authorization',
    'card_number', 'pan', 'cvv', 'cvc', 'pin', 'pin_code', 'otp',
    'merchant_key', 'private_key', 'signature', 'credential', 'credentials',
    'encryption_key', 'signingKey', 'app_key',
]);

it('leaves ordinary values alone', function (): void {
    $payload = ['name' => 'Barbershop Alpha', 'status' => 'active', 'sequence' => 7];

    expect((new Redactor)->redact($payload))->toBe($payload);
});

it('redacts nested payloads', function (): void {
    $result = (new Redactor)->redact([
        'provider' => [
            'code' => 'zaincash',
            'credentials' => ['merchant_id' => 'M1', 'secret' => 'shh'],
        ],
    ]);

    expect($result['provider']['code'])->toBe('zaincash')
        // `credentials` matches, so the whole branch is replaced.
        ->and($result['provider']['credentials'])->toBe(Redactor::PLACEHOLDER);
});

it('handles null', function (): void {
    expect((new Redactor)->redact(null))->toBeNull();
});

it('does not flag innocuous keys', function (string $key): void {
    expect((new Redactor)->isSensitive($key))->toBeFalse();
})->with([
    'name', 'status', 'branch_id', 'created_at', 'schema_version', 'database',
    // Whole-segment matching, not substrings: these are the false positives a
    // naive `str_contains` produces, and they would strip exactly the context
    // that makes an audit entry worth keeping.
    'merchant_id', 'company_name', 'shipping', 'panel_layout', 'spinner',
    'tenant_key_name', 'keyboard',
]);
