<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Exceptions\InvalidTenantDatabaseName;
use App\Kernel\Tenancy\TenantDatabaseName;

/*
|--------------------------------------------------------------------------
| Tenant database naming
|--------------------------------------------------------------------------
|
| ADR-024. Database identifiers cannot be bound as SQL parameters, so every
| name that reaches a DDL statement is interpolated. The defence is that names
| are generated and validated, never accepted.
|
| Runs against the PRODUCTION prefix rather than the suite's test override, so
| the assertions describe the real naming scheme.
|
*/

beforeEach(function (): void {
    config(['metastyle.tenancy.database_prefix' => TenantDatabaseName::DEFAULT_PREFIX]);
});

it('pads the sequence to a stable width', function (int $sequence, string $expected): void {
    expect(TenantDatabaseName::forSequence($sequence))->toBe($expected);
})->with([
    [1, 'tenant_000001'],
    [2, 'tenant_000002'],
    [1234, 'tenant_001234'],
    [999999, 'tenant_999999'],
    // Past six digits it widens rather than truncating or colliding.
    [1000000, 'tenant_1000000'],
]);

it('rejects a non-positive sequence', function (int $sequence): void {
    TenantDatabaseName::forSequence($sequence);
})->with([[0], [-1]])->throws(InvalidTenantDatabaseName::class);

it('accepts only names it could have generated', function (string $name, bool $valid): void {
    expect(TenantDatabaseName::isValid($name))->toBe($valid);
})->with([
    ['tenant_000001', true],
    ['tenant_123456789', true],
    ['mysql', false],
    ['meta_style_control', false],
    ['tenant_', false],
    ['tenant_12345', false],
    ['tenant_000001; DROP DATABASE meta_style_control', false],
    ['tenant_000001`', false],
    ['`tenant_000001`', false],
    ['tenant_00000a', false],
    ['TENANT_000001', false],
]);

it('refuses a name outside the policy', function (): void {
    TenantDatabaseName::assertValid('information_schema');
})->throws(InvalidTenantDatabaseName::class);

it('returns the name when it is valid', function (): void {
    expect(TenantDatabaseName::assertValid('tenant_000007'))->toBe('tenant_000007');
});

it('rejects an unsafe configured prefix', function (): void {
    // Config is not user input, but it is the last way a string could reach a
    // DDL statement, so it is validated rather than trusted.
    config(['metastyle.tenancy.database_prefix' => 'tenant`; DROP DATABASE x; --']);

    TenantDatabaseName::forSequence(1);
})->throws(InvalidTenantDatabaseName::class);
