<?php

declare(strict_types=1);

use App\Kernel\SaaS\Models\Registration;
use App\Modules\Onboarding\Application\RegistrationService;
use App\Modules\Onboarding\Infrastructure\Jobs\ProvisionRegisteredTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/*
|--------------------------------------------------------------------------
| Registration credential exposure
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-028, ADR-031 · docs/08-AUDIT-SECURITY.md §17.
|
| RegistrationSecretHandlingTest covers the database. This file walks every
| OTHER way a credential leaves a process: serialisation, API resources, log
| lines, exception text, audit payloads, and the queue payloads that outlive
| everything else.
|
| ADR-031 keeps a password hash on the registration row for up to a day, so
| "the hash is only a hash" is not good enough. It is bcrypt over the owner's
| real password, and the owner very likely reuses it.
|
*/

const EXPOSURE_PLAINTEXT = 'correct-horse-battery-staple-8823';

function registerForExposure(string $centerName = 'Exposure Center'): Registration
{
    $issued = app(RegistrationService::class)->register([
        'center_name' => $centerName,
        'owner_name' => 'Owner',
        'owner_email' => 'owner@exposure.test',
        'owner_phone' => '+9647700000000',
        'password' => EXPOSURE_PLAINTEXT,
        'locale' => 'en',
        'country' => 'IQ',
    ], 'exposure:'.$centerName);

    exposureToken($issued['registration']->uuid, (string) $issued['access_token']);

    return $issued['registration'];
}

/**
 * Remembers (or returns) the one-time capability for a registration, so the API
 * walk below can actually reach the endpoints it is auditing.
 */
function exposureToken(string $uuid, ?string $token = null): string
{
    static $tokens = [];

    if ($token !== null) {
        $tokens[$uuid] = $token;
    }

    return $tokens[$uuid] ?? '';
}

/**
 * Everything the credential must never appear inside.
 *
 * @return list<string>
 */
function forbiddenValues(Registration $registration): array
{
    $values = [EXPOSURE_PLAINTEXT];

    $hash = $registration->owner_password_hash;

    if (is_string($hash) && $hash !== '') {
        $values[] = $hash;
    }

    return $values;
}

it('excludes the credential from model serialisation', function (): void {
    $registration = registerForExposure();

    // toArray() and toJson() are what a resource, a log context, an event
    // payload or a `dd()` in production would reach for.
    $serialised = [
        json_encode($registration->toArray(), JSON_THROW_ON_ERROR),
        $registration->toJson(),
        json_encode($registration, JSON_THROW_ON_ERROR),
        json_encode($registration->toStatusPayload(), JSON_THROW_ON_ERROR),
        json_encode(['registration' => $registration], JSON_THROW_ON_ERROR),
    ];

    foreach ($serialised as $output) {
        expect($output)->not->toContain(EXPOSURE_PLAINTEXT)
            ->not->toContain('owner_password_hash');
    }

    // The attribute is still readable in code, deliberately — provisioning
    // needs it. Only the serialised forms hide it.
    expect($registration->owner_password_hash)->toBeString()->not->toBeEmpty();
});

it('carries nothing but a uuid across the queue boundary', function (): void {
    $registration = registerForExposure();

    $job = new ProvisionRegisteredTenant($registration->uuid);

    $serialised = serialize($job);

    expect($serialised)->toContain($registration->uuid);

    foreach (forbiddenValues($registration) as $secret) {
        expect($serialised)->not->toContain($secret);
    }

    // Not "no secret in the payload" but "nothing else in the payload at all":
    // a job that took the Registration model would serialise every attribute
    // through SerializesModels the moment someone changed the constructor.
    $properties = array_keys(get_object_vars($job));

    sort($properties);

    expect($job->registrationUuid)->toBe($registration->uuid)
        ->and(array_values(array_diff($properties, [
            // Everything the Queueable/InteractsWithQueue traits contribute.
            'registrationUuid', 'job', 'connection', 'queue', 'chainConnection',
            'chainQueue', 'chainCatchCallbacks', 'delay', 'afterCommit',
            'middleware', 'chained', 'tries', 'deduplicator', 'messageGroup',
        ])))->toBe([]);
});

it('keeps the credential out of every log line during a failure and a retry', function (): void {
    $lines = [];

    // Capture what actually reaches the logger, not what we hope reaches it.
    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $registration = registerForExposure('Logged Center');
    $secrets = forbiddenValues($registration);

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);
    $this->runProvisioning($registration);
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    app(RegistrationService::class)->retry($registration->refresh());
    $this->runProvisioning($registration);

    expect($secrets)->toHaveCount(2);

    foreach ($lines as $line) {
        foreach ($secrets as $secret) {
            expect($line)->not->toContain($secret);
        }
    }

    $this->trackRegistrationDatabase($registration);
});

it('sanitises the failure text stored on the registration and in the audit trail', function (): void {
    $registration = registerForExposure('Sanitised Center');
    $secrets = forbiddenValues($registration);

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);
    $this->runProvisioning($registration);
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    $registration->refresh();

    // An exception message is written by whoever threw it — a driver, a
    // package, us — so it is treated as untrusted text on the way to storage.
    expect($registration->error)->toBeString()->not->toBeEmpty();

    foreach ($secrets as $secret) {
        expect((string) $registration->error)->not->toContain($secret);
    }

    $audit = DB::connection('control')->table('platform_audit_logs')
        ->where('action', 'saas.registration.failed')
        ->first();

    expect($audit)->not->toBeNull();

    foreach ((array) $audit as $value) {
        if (is_scalar($value)) {
            foreach ($secrets as $secret) {
                expect((string) $value)->not->toContain($secret);
            }
        }
    }

    $this->trackRegistrationDatabase($registration);
});

it('records a retry and an abandonment without recording what they protect', function (): void {
    $registration = registerForExposure('Audited Center');
    $secrets = forbiddenValues($registration);

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);
    $this->runProvisioning($registration);
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    app(RegistrationService::class)->retry($registration->refresh());

    $registration->refresh()->forceFill(['credentials_expire_at' => Carbon::now()->subMinute()])->save();
    app(RegistrationService::class)->cancel($registration->refresh(), 'test');

    $entries = DB::connection('control')->table('platform_audit_logs')
        ->whereIn('action', ['saas.registration.retried', 'saas.registration.cancelled'])
        ->get();

    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        foreach ((array) $entry as $value) {
            if (is_scalar($value)) {
                foreach ($secrets as $secret) {
                    expect((string) $value)->not->toContain($secret);
                }
            }
        }
    }

    $this->trackRegistrationDatabase($registration);
});

it('exposes no credential through the public API surface, in any state', function (): void {
    $registration = registerForExposure('Api Center');
    $secrets = forbiddenValues($registration);

    $headers = $this->registrationHeaders(exposureToken($registration->uuid));

    $bodies = [
        (string) $this->withHeaders($headers)
            ->getJson("/api/v1/public/registrations/{$registration->uuid}")->getContent(),
    ];

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);
    $this->runProvisioning($registration);
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    $bodies[] = (string) $this->withHeaders($headers)
        ->getJson("/api/v1/public/registrations/{$registration->uuid}")->getContent();
    $bodies[] = (string) $this->withHeaders($headers)
        ->postJson("/api/v1/public/registrations/{$registration->uuid}/retry")->getContent();

    // Retried into readiness, then a refused second retry.
    $this->runProvisioning($registration);

    $bodies[] = (string) $this->withHeaders($headers)
        ->getJson("/api/v1/public/registrations/{$registration->uuid}")->getContent();
    $bodies[] = (string) $this->withHeaders($headers)
        ->postJson("/api/v1/public/registrations/{$registration->uuid}/retry")->getContent();

    expect($bodies)->toHaveCount(5);

    foreach ($bodies as $body) {
        foreach ($secrets as $secret) {
            expect($body)->not->toContain($secret);
        }

        expect($body)->not->toContain('owner_password_hash')
            ->not->toContain('credentials_expire_at');
    }

    $this->trackRegistrationDatabase($registration);
});

it('writes no failed job rows carrying registration data', function (): void {
    $registration = registerForExposure('Failed Job Center');
    $secrets = forbiddenValues($registration);

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);
    $this->runProvisioning($registration);
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    $failed = DB::connection('control')->table('failed_jobs')->get();

    // The job catches its own failures and records them on the registration,
    // so nothing should reach failed_jobs at all. Asserted rather than assumed:
    // if that ever changes, the loop below starts doing real work.
    expect($failed->count())->toBe(0);

    // A failed_jobs row can sit in a production database for years, so it is
    // the single most permanent place a leak could land.
    foreach ($failed as $row) {
        foreach ((array) $row as $value) {
            if (is_scalar($value)) {
                foreach ($secrets as $secret) {
                    expect((string) $value)->not->toContain($secret);
                }
            }
        }
    }

    $this->trackRegistrationDatabase($registration);
});

it('keeps the credential out of tenant_operations', function (): void {
    $result = $this->registerCenter('Ops Center', 'owner@ops.test', EXPOSURE_PLAINTEXT);

    $rows = DB::connection('control')->table('tenant_operations')->get();

    // Provisioning writes operation rows; an empty table would mean this test
    // is inspecting nothing.
    expect($rows->count())->toBeGreaterThan(0);

    foreach ($rows as $row) {
        foreach ((array) $row as $value) {
            if (is_scalar($value)) {
                expect((string) $value)->not->toContain(EXPOSURE_PLAINTEXT);
            }
        }
    }

    unset($result);
});

it('resolves a logger that is not silently discarding these assertions', function (): void {
    // Guards the log test above: if the null driver were configured, that test
    // would pass by capturing nothing at all.
    expect(app(LoggerInterface::class))->not->toBeNull()
        ->and(config('logging.default'))->not->toBe('null');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
