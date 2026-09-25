<?php

declare(strict_types=1);

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Registration retry and credential lifecycle
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-031.
|
| Phase 3 destroyed the bootstrap credential the moment provisioning failed,
| which made every failed self-registration permanently unrecoverable: the
| owner's password was gone, so no retry could ever create their account. These
| tests hold the line on both halves of the fix — the credential survives long
| enough to be useful, and not one moment longer.
|
*/

const RETRY_PLAINTEXT = 'correct-horse-battery-staple-4417';

/**
 * Registers a center and fails provisioning AFTER the tenant, database, schema,
 * roles, branch and owner all exist.
 *
 * Deactivating the trial plan makes `defaultPlan()` throw at the last step, so
 * the registration lands in exactly the state a retry has to cope with: most of
 * a center already built. A failure before anything was created would prove
 * nothing about idempotency.
 */
function failProvisioningLate(string $centerName, string $email): Registration
{
    $registration = app(RegistrationService::class)->register([
        'center_name' => $centerName,
        'owner_name' => 'Owner of '.$centerName,
        'owner_email' => $email,
        'owner_phone' => '+9647701234567',
        'password' => RETRY_PLAINTEXT,
        'locale' => 'en',
        'country' => 'IQ',
    ], 'retry-test:'.$email)['registration'];

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);

    try {
        test()->runProvisioning($registration);
    } finally {
        DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);
    }

    test()->trackRegistrationDatabase($registration);

    return $registration->refresh();
}

/** The encrypted column as it actually sits on disk. */
function storedCredential(Registration $registration): ?string
{
    /** @var string|null $value */
    $value = DB::connection('control')
        ->table('registrations')
        ->where('uuid', $registration->uuid)
        ->value('owner_password_hash');

    return $value;
}

it('leaves a failed registration retryable, with its credential intact', function (): void {
    $registration = failProvisioningLate('Retryable Center', 'owner@retryable.test');

    expect($registration->status)->toBe(RegistrationStatus::Failed)
        ->and($registration->isRetryable())->toBeTrue()
        ->and($registration->hasCredential())->toBeTrue()
        ->and($registration->credentials_expire_at)->not->toBeNull();

    // Still on disk, still encrypted, and still the right credential.
    expect(storedCredential($registration))->not->toBeNull()
        ->not->toStartWith('$2y$')
        ->not->toContain(RETRY_PLAINTEXT);

    expect(Hash::check(RETRY_PLAINTEXT, (string) $registration->owner_password_hash))->toBeTrue();
});

it('resumes a failed registration without duplicating anything', function (): void {
    $registration = failProvisioningLate('Resumed Center', 'owner@resumed.test');

    $tenantId = $registration->tenant_id;

    expect($tenantId)->not->toBeNull();

    $tenantsBefore = TenantModel::query()->count();
    $databasesBefore = TenantModel::query()->pluck('tenancy_db_name')->unique()->count();

    expect(app(RegistrationService::class)->retry($registration))->toBeTrue();

    test()->runProvisioning($registration);

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Ready)
        // Same tenant. A second one would mean a second database and a second
        // bill for the same signup.
        ->and($registration->tenant_id)->toBe($tenantId)
        ->and(TenantModel::query()->count())->toBe($tenantsBefore)
        ->and(TenantModel::query()->pluck('tenancy_db_name')->unique()->count())->toBe($databasesBefore);

    expect(DB::connection('control')->table('subscriptions')->where('tenant_id', $tenantId)->count())->toBe(1);

    $tenant = TenantModel::query()->findOrFail($tenantId)->toValueObject();

    $counts = test()->asCenter($tenant, fn (): array => [
        'branches' => Branch::query()->count(),
        'main_branches' => Branch::query()->where('is_main', true)->count(),
        'owners' => User::query()->where('is_owner', true)->count(),
        'users' => User::query()->count(),
        'roles' => Role::query()->count(),
        'system_roles' => Role::query()->where('is_system', true)->count(),
    ]);

    expect($counts['branches'])->toBe(1)
        ->and($counts['main_branches'])->toBe(1)
        ->and($counts['users'])->toBe(1)
        ->and($counts['owners'])->toBe(1)
        // Re-seeding must not produce a second Owner/Manager/... set.
        ->and($counts['roles'])->toBe($counts['system_roles']);

    // Role permissions are a join table, and a naive re-sync is exactly where a
    // duplicate would hide.
    $duplicatePermissions = test()->asCenter($tenant, fn (): int => (int) DB::connection('tenant')
        ->table('role_permissions')
        ->select('role_id', 'permission')
        ->groupBy('role_id', 'permission')
        ->havingRaw('COUNT(*) > 1')
        ->get()
        ->count());

    expect($duplicatePermissions)->toBe(0);
});

it('gives the owner a working password after a retry', function (): void {
    $registration = failProvisioningLate('Late Owner Center', 'owner@lateowner.test');

    app(RegistrationService::class)->retry($registration);
    test()->runProvisioning($registration);

    $tenant = TenantModel::query()->findOrFail($registration->refresh()->tenant_id)->toValueObject();

    // The point of keeping the credential: without it this account could not
    // exist, and the owner could never sign in to the center they registered.
    $owner = test()->ownerOf($tenant);

    expect(Hash::check(RETRY_PLAINTEXT, (string) $owner->password))->toBeTrue();
});

it('destroys the bootstrap credential once a retry succeeds', function (): void {
    $registration = failProvisioningLate('Cleared On Retry', 'owner@clearedretry.test');

    app(RegistrationService::class)->retry($registration);
    test()->runProvisioning($registration);

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Ready)
        ->and(storedCredential($registration))->toBeNull()
        ->and($registration->credentials_expire_at)->toBeNull()
        ->and($registration->settled_at)->not->toBeNull();
});

it('destroys the bootstrap credential on a first-attempt success', function (): void {
    $result = test()->registerCenter('First Try Center', 'owner@firsttry.test', RETRY_PLAINTEXT);

    expect(storedCredential($result['registration']))->toBeNull()
        ->and($result['registration']->refresh()->settled_at)->not->toBeNull();
});

it('refuses a retry once the credential window has closed', function (): void {
    $registration = failProvisioningLate('Expired Center', 'owner@expired.test');

    $registration->forceFill(['credentials_expire_at' => Carbon::now()->subMinute()])->save();

    expect($registration->isRetryable())->toBeFalse();

    $queuedBefore = DB::connection('control')->table('jobs')->count();

    expect(app(RegistrationService::class)->retry($registration))->toBeFalse()
        ->and($registration->refresh()->status)->toBe(RegistrationStatus::Failed)
        // Refused means refused: no work queued that could only fail.
        ->and(DB::connection('control')->table('jobs')->count())->toBe($queuedBefore);
});

it('sweeps expired registrations and destroys their credentials', function (): void {
    $registration = failProvisioningLate('Swept Center', 'owner@swept.test');

    $registration->forceFill(['credentials_expire_at' => Carbon::now()->subHour()])->save();

    expect(app(RegistrationService::class)->sweepAbandoned())->toBe(1);

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Abandoned)
        ->and(storedCredential($registration))->toBeNull()
        ->and($registration->isRetryable())->toBeFalse()
        // The failure reason survives abandonment: it is what support needs
        // when the owner asks what happened.
        ->and($registration->error)->not->toBeNull();
});

it('leaves registrations inside their window alone when sweeping', function (): void {
    $registration = failProvisioningLate('Untouched Center', 'owner@untouched.test');

    expect(app(RegistrationService::class)->sweepAbandoned())->toBe(0)
        ->and($registration->refresh()->status)->toBe(RegistrationStatus::Failed)
        ->and(storedCredential($registration))->not->toBeNull();
});

it('destroys the credential when a registration is cancelled', function (): void {
    $registration = failProvisioningLate('Cancelled Center', 'owner@cancelled.test');

    expect(app(RegistrationService::class)->cancel($registration, 'requested by owner'))->toBeTrue();

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Cancelled)
        ->and(storedCredential($registration))->toBeNull()
        ->and($registration->isRetryable())->toBeFalse();
});

it('never lets the plaintext password reach storage across a failure and retry', function (): void {
    $registration = failProvisioningLate('Plaintext Center', 'owner@plaintext.test');

    app(RegistrationService::class)->retry($registration);
    test()->runProvisioning($registration);

    // Every control-plane table that could outlive the request, including the
    // failed-job and audit trails written by the failure itself.
    foreach (['registrations', 'jobs', 'failed_jobs', 'platform_audit_logs', 'tenant_operations'] as $table) {
        foreach (DB::connection('control')->table($table)->get() as $row) {
            foreach ((array) $row as $value) {
                if (is_scalar($value)) {
                    expect((string) $value)->not->toContain(RETRY_PLAINTEXT);
                }
            }
        }
    }
});

it('will not retry a registration that already succeeded', function (): void {
    $result = test()->registerCenter('Already Ready', 'owner@alreadyready.test', RETRY_PLAINTEXT);

    $registration = $result['registration']->refresh();

    expect($registration->isRetryable())->toBeFalse()
        ->and(app(RegistrationService::class)->retry($registration))->toBeFalse()
        ->and($registration->refresh()->status)->toBe(RegistrationStatus::Ready);
});

afterEach(function (): void {
    test()->tearDownRegisteredCenters();
});
