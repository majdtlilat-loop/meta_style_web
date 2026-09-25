<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Exceptions\InvalidTenantDatabaseName;
use App\Kernel\Tenancy\TenantDatabaseName;

/*
|--------------------------------------------------------------------------
| Tenant database naming
|--------------------------------------------------------------------------
|
| ADR-024, amended by ADR-106. Database identifiers cannot be bound as SQL
| parameters, so every name that reaches a DDL statement is interpolated. The
| defence is that names are GENERATED and validated, never accepted.
|
| A new center's database reads `tenant_{label}_{sequence}`: the label is the
| center's slug reduced to a closed alphabet, the suffix is the internal
| sequence, which alone makes the name unique. Databases created before the
| change keep their `tenant_{sequence}` names and stay valid.
|
| Runs against the PRODUCTION prefix rather than the suite's test override, so
| the assertions describe the real naming scheme.
|
*/

beforeEach(function (): void {
    config(['metastyle.tenancy.database_prefix' => TenantDatabaseName::DEFAULT_PREFIX]);
});

it('names a new center\'s database after its slug and its sequence', function (int $sequence, string $slug, string $expected): void {
    expect(TenantDatabaseName::generate($sequence, $slug))->toBe($expected);
})->with([
    [3, 'drbany', 'tenant_drbany_000003'],
    [4, 'barbershop-alpha', 'tenant_barbershop_alpha_000004'],
    [5, 'qa-test-center', 'tenant_qa_test_center_000005'],
    [1234, 'salon', 'tenant_salon_001234'],
    // Past six digits the suffix widens rather than truncating or colliding.
    [1000000, 'salon', 'tenant_salon_1000000'],
]);

it('reduces anything a slug could carry to lowercase letters, digits and single underscores', function (string $slug, string $expected): void {
    $name = TenantDatabaseName::generate(7, $slug);

    expect($name)->toBe($expected)
        ->and(preg_match('/^[a-z0-9_]+$/', $name))->toBe(1)
        ->and(TenantDatabaseName::isValid($name))->toBeTrue();
})->with([
    'spaces and case' => ['Dr Bany', 'tenant_dr_bany_000007'],
    'hyphens' => ['dr--bany-', 'tenant_dr_bany_000007'],
    'sql injection' => ["salon'; DROP DATABASE x; --", 'tenant_salon_drop_database_x_000007'],
    'backticks' => ['sa`lon`', 'tenant_sa_lon_000007'],
    'quotes' => ['"salon"', 'tenant_salon_000007'],
    'dots and slashes' => ['../../etc/passwd', 'tenant_etc_passwd_000007'],
    'backslashes' => ['a\\b\\c', 'tenant_a_b_c_000007'],
    'wildcards' => ['sal%on_*', 'tenant_sal_on_000007'],
    'whitespace' => ["  sa\tl\non  ", 'tenant_sa_l_on_000007'],
    'underscores' => ['__dr__bany__', 'tenant_dr_bany_000007'],
    'digits only' => ['2026', 'tenant_2026_000007'],
]);

it('never depends on a unicode identifier: a slug with nothing ASCII left becomes a neutral label', function (string $slug): void {
    expect(TenantDatabaseName::generate(9, $slug))->toBe('tenant_center_000009');
})->with([
    'arabic' => ['مركز التجميل'],
    'kurdish' => ['ناوەندی جوانی'],
    'symbols only' => ['!@#$%^&*()'],
    'empty' => [''],
]);

it('keeps the ASCII part of a partly unicode slug', function (): void {
    expect(TenantDatabaseName::generate(9, 'salon-مركز-erbil'))->toBe('tenant_salon_erbil_000009');
});

it('names a center with no slug at all with the neutral label', function (): void {
    expect(TenantDatabaseName::generate(9, null))->toBe('tenant_center_000009');
});

it('bounds the slug before the suffix, so the name fits the identifier limit and the suffix is never cut', function (): void {
    $slug = str_repeat('abcdefghij', 6).'xyz'; // 63 characters, the longest a slug may be

    $name = TenantDatabaseName::generate(123456789012, $slug);

    expect(strlen($name))->toBeLessThanOrEqual(TenantDatabaseName::MAX_LENGTH)
        ->and($name)->toBe('tenant_'.substr($slug, 0, TenantDatabaseName::MAX_SLUG_LENGTH).'_123456789012')
        ->and(TenantDatabaseName::isValid($name))->toBeTrue();
});

it('never leaves a dangling underscore where the slug was cut', function (): void {
    // The 24th character is a hyphen: cutting there must not end the label in "_".
    $slug = 'abcdefghijklmnopqrstuvw-xyz';

    expect(TenantDatabaseName::generate(1, $slug))->toBe('tenant_abcdefghijklmnopqrstuvw_000001');
});

it('shortens the slug further under the longest prefix the suite may configure', function (): void {
    // The namespaced test prefix may be 33 characters; a 12-digit sequence is
    // the widest suffix. The slug gives way, never the suffix.
    config(['metastyle.tenancy.database_prefix' => 'meta_style_test_ns_abcdefghijkl_t']);

    $name = TenantDatabaseName::generate(999999999999, str_repeat('z', 63));

    expect(strlen($name))->toBe(TenantDatabaseName::MAX_LENGTH)
        ->and($name)->toEndWith('_999999999999')
        ->and(TenantDatabaseName::isValid($name))->toBeTrue();
});

it('refuses a name over the identifier limit, however well shaped', function (): void {
    config(['metastyle.tenancy.database_prefix' => 'meta_style_test_ns_abcdefghijkl_t']);

    // 33 + 24 + 1 + 12 = 70 characters: the generator would never make it.
    expect(TenantDatabaseName::isValid('meta_style_test_ns_abcdefghijkl_t'.str_repeat('a', 24).'_123456789012'))->toBeFalse()
        ->and(TenantDatabaseName::isValid('meta_style_test_ns_abcdefghijkl_t'.str_repeat('a', 18).'_123456789012'))->toBeTrue();
});

it('keeps two centers with similar or identical slugs apart by their sequence', function (): void {
    $names = [
        TenantDatabaseName::generate(10, 'dr-bany'),
        TenantDatabaseName::generate(11, 'dr_bany'),
        TenantDatabaseName::generate(12, 'Dr Bany'),
        TenantDatabaseName::generate(13, 'drbany'),
    ];

    // The first three reduce to the same label; only the suffix tells them
    // apart — which is exactly why the suffix is never optional or truncated.
    expect(array_unique($names))->toHaveCount(4)
        ->and($names[0])->toBe('tenant_dr_bany_000010')
        ->and($names[1])->toBe('tenant_dr_bany_000011');
});

it('rejects a sequence it cannot represent', function (int $sequence): void {
    TenantDatabaseName::generate($sequence, 'salon');
})->with([[0], [-1], [1000000000000]])->throws(InvalidTenantDatabaseName::class);

it('accepts only names it could have generated, old and new', function (string $name, bool $valid): void {
    expect(TenantDatabaseName::isValid($name))->toBe($valid);
})->with([
    // Databases provisioned before ADR-106 keep their names.
    ['tenant_000001', true],
    ['tenant_123456789', true],
    ['tenant_drbany_000003', true],
    ['tenant_barbershop_alpha_000004', true],
    ['tenant_2026_000007', true],
    ['mysql', false],
    ['meta_style_control', false],
    ['tenant_', false],
    ['tenant_12345', false],
    ['tenant_drbany_', false],
    ['tenant_drbany_12345', false],
    ['tenant__000003', false],
    ['tenant_drbany__000003', false],
    ['tenant_dr__bany_000003', false],
    ['tenant__drbany_000003', false],
    ['tenant_DrBany_000003', false],
    ['tenant_dr-bany_000003', false],
    ['tenant_dr.bany_000003', false],
    ['tenant_dr/bany_000003', false],
    ['tenant_dr%bany_000003', false],
    ['tenant_drbany_000003;', false],
    ['tenant_000001; DROP DATABASE meta_style_control', false],
    ['tenant_000001`', false],
    ['`tenant_000001`', false],
    ['tenant_00000a', false],
    ['TENANT_000001', false],
    // PCRE's `$` also matches before a final newline: a name with one must
    // still be refused, or it would reach a DDL statement.
    ["tenant_000001\n", false],
    ["tenant_drbany_000003\n", false],
    ["tenant_drbany_000003\r\n", false],
    ["tenant_drbany\n_000003", false],
    // A label one character longer than the generator ever makes.
    ['tenant_'.str_repeat('a', 25).'_000001', false],
    ['tenant_'.str_repeat('a', 24).'_000001', true],
]);

it('refuses a name outside the policy', function (): void {
    TenantDatabaseName::assertValid('information_schema');
})->throws(InvalidTenantDatabaseName::class);

it('returns the name when it is valid', function (): void {
    expect(TenantDatabaseName::assertValid('tenant_000007'))->toBe('tenant_000007')
        ->and(TenantDatabaseName::assertValid('tenant_drbany_000003'))->toBe('tenant_drbany_000003');
});

it('rejects a configured prefix with a trailing newline', function (): void {
    config(['metastyle.tenancy.database_prefix' => "tenant_\n"]);

    TenantDatabaseName::generate(1, 'salon');
})->throws(InvalidTenantDatabaseName::class);

it('rejects an unsafe configured prefix', function (): void {
    // Config is not user input, but it is the last way a string could reach a
    // DDL statement, so it is validated rather than trusted.
    config(['metastyle.tenancy.database_prefix' => 'tenant`; DROP DATABASE x; --']);

    TenantDatabaseName::generate(1, 'salon');
})->throws(InvalidTenantDatabaseName::class);
