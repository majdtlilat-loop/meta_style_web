<?php

declare(strict_types=1);

use App\Kernel\SaaS\Models\Registration;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Registration secret handling
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-028.
|
| The owner's password crosses a queue boundary during provisioning. These
| tests exist because a failed job row can sit in the database indefinitely,
| and anything in that payload should be assumed permanent.
|
| How long the credential is kept, and what destroys it, is ADR-031 and lives
| in RegistrationRetryTest.php. This file is about what must never exist at all.
|
*/

const PLAINTEXT = 'correct-horse-battery-staple-9271';

/**
 * @return list<string> every column value that could plausibly hold a secret
 */
function controlPlaneHaystack(): array
{
    $values = [];

    foreach (['registrations', 'jobs', 'failed_jobs', 'platform_audit_logs', 'tenant_operations'] as $table) {
        foreach (DB::connection('control')->table($table)->get() as $row) {
            foreach ((array) $row as $value) {
                if (is_scalar($value)) {
                    $values[] = (string) $value;
                }
            }
        }
    }

    return $values;
}

it('never writes the plaintext password anywhere in the control plane', function (): void {
    $this->registerCenter('Alpha', 'owner@alpha.test', PLAINTEXT);

    foreach (controlPlaneHaystack() as $value) {
        expect($value)->not->toContain(PLAINTEXT);
    }
});

it('keeps the credential out of the queued job payload', function (): void {
    $registration = app(RegistrationService::class)->register([
        'center_name' => 'Queued Center',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@queued.test',
        'owner_phone' => '+9647701234567',
        'password' => PLAINTEXT,
    ], 'idem-queue-secret')['registration'];

    $payload = (string) DB::connection('control')->table('jobs')->value('payload');

    // Only the uuid travels. Not the password, not its hash, not the email.
    expect($payload)->toContain($registration->uuid)
        ->not->toContain(PLAINTEXT)
        ->not->toContain((string) $registration->getRawOriginal('owner_password_hash'));

    $this->runProvisioning($registration);
    $this->trackRegistrationDatabase($registration);
});

it('stores the hash encrypted, not as a readable bcrypt string', function (): void {
    app(RegistrationService::class)->register([
        'center_name' => 'Encrypted Center',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@enc.test',
        'owner_phone' => '+9647701234567',
        'password' => PLAINTEXT,
    ], 'idem-encrypted')['registration'];

    $raw = (string) DB::connection('control')->table('registrations')->value('owner_password_hash');

    // A readable `$2y$` prefix would mean the column holds a usable credential
    // at rest.
    expect($raw)->not->toStartWith('$2y$')
        ->not->toContain(PLAINTEXT)
        ->not->toBeEmpty();
});

it('clears the credential once provisioning succeeds', function (): void {
    $result = $this->registerCenter('Cleared Center', 'owner@cleared.test', PLAINTEXT);

    $stored = DB::connection('control')
        ->table('registrations')
        ->where('uuid', $result['registration']->uuid)
        ->value('owner_password_hash');

    expect($stored)->toBeNull();
});

it('keeps the credential when provisioning fails, but only encrypted', function (): void {
    $registration = app(RegistrationService::class)->register([
        'center_name' => 'Failing Center',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@failing.test',
        'owner_phone' => '+9647701234567',
        'password' => PLAINTEXT,
    ], 'idem-failing-secret')['registration'];

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);

    $this->runProvisioning($registration);

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    // ADR-031 revises ADR-028 here. Destroying the hash on failure made every
    // failed registration unrecoverable — the owner's password was gone, so no
    // retry could ever create their account. It is kept, encrypted, until the
    // window closes; tests/Feature/Saas/RegistrationRetryTest.php owns the
    // rest of that lifecycle.
    $stored = (string) DB::connection('control')->table('registrations')->value('owner_password_hash');

    expect($stored)->not->toBeEmpty()
        ->not->toStartWith('$2y$')
        ->not->toContain(PLAINTEXT);

    $this->trackRegistrationDatabase($registration);
});

it('produces an owner account whose password actually works', function (): void {
    $result = $this->registerCenter('Working Center', 'owner@working.test', PLAINTEXT);

    $owner = $this->ownerOf($result['tenant']);

    // Proves the hash survived the round trip intact. It must NOT have been
    // re-hashed on the way into the tenant database — hashing a hash would
    // lock the owner out of their own center, and only a real credential check
    // catches that.
    expect(Hash::check(PLAINTEXT, (string) $owner->password))->toBeTrue()
        ->and($owner->canAuthenticate())->toBeTrue();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
