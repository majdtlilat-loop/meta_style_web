<?php

declare(strict_types=1);

use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\RegistrationAccessToken;
use App\Kernel\SaaS\RegistrationSession;
use App\Livewire\Auth\RegistrationStatus as RegistrationStatusPage;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Registration access capability
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-035.
|
| A registration has two identifiers doing two different jobs:
|
|   uuid   public LOCATOR   — travels in a redirect URL, browser history, a
|                             support ticket, a screenshot. Not a secret.
|   token  secret CAPABILITY — never stored in plaintext, never in a URL,
|                             handed to the registering client exactly once.
|
| Phase 3.1 conflated them, so anyone holding a uuid could read a stranger's
| registration and enqueue provisioning work on it. These tests hold the two
| apart.
|
*/

const CAPABILITY_PLAINTEXT = 'correct-horse-battery-staple-6612';

/**
 * @return array{registration: Registration, access_token: string}
 */
function issueRegistration(string $centerName = 'Capability Center', string $email = 'owner@capability.test'): array
{
    $issued = app(RegistrationService::class)->register([
        'center_name' => $centerName,
        'owner_name' => 'Owner',
        'owner_email' => $email,
        'password' => CAPABILITY_PLAINTEXT,
        'locale' => 'en',
        'country' => 'IQ',
    ], 'capability:'.$email);

    return [
        'registration' => $issued['registration'],
        'access_token' => (string) $issued['access_token'],
    ];
}

/** Drives a registration into `failed`, where retry is legitimate. */
function failIssuedRegistration(Registration $registration): Registration
{
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);

    try {
        test()->runProvisioning($registration);
    } finally {
        DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);
    }

    test()->trackRegistrationDatabase($registration);

    return $registration->refresh();
}

/*
|--------------------------------------------------------------------------
| The uuid alone grants nothing
|--------------------------------------------------------------------------
*/

it('refuses to read registration state with the uuid alone', function (): void {
    $issued = issueRegistration();

    $this->getJson("/api/v1/public/registrations/{$issued['registration']->uuid}")
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'RESOURCE.NOT_FOUND');
});

it('refuses to retry with the uuid alone', function (): void {
    $issued = failIssuedRegistration(issueRegistration()['registration']);

    expect($issued->isRetryable())->toBeTrue();

    $queuedBefore = DB::connection('control')->table('jobs')->count();

    $this->postJson("/api/v1/public/registrations/{$issued->uuid}/retry")
        ->assertStatus(404);

    // Refused before anything was enqueued. A 404 that still queued the work
    // would be the whole vulnerability with a different status code.
    expect(DB::connection('control')->table('jobs')->count())->toBe($queuedBefore)
        ->and($issued->refresh()->status)->toBe(RegistrationStatus::Failed);
});

it('rejects a wrong token, and says nothing different from an unknown uuid', function (): void {
    $issued = issueRegistration();

    $wrong = $this->withHeaders($this->registrationHeaders(RegistrationAccessToken::generate()))
        ->getJson("/api/v1/public/registrations/{$issued['registration']->uuid}");

    $unknown = $this->withHeaders($this->registrationHeaders(RegistrationAccessToken::generate()))
        ->getJson('/api/v1/public/registrations/'.Str::uuid()->toString());

    // Byte-identical bodies. Anything else turns the endpoint into an oracle
    // for "does this registration exist".
    $wrong->assertStatus(404);
    $unknown->assertStatus(404);

    expect($wrong->json('error'))->toBe($unknown->json('error'));
});

it('rejects a token belonging to a different registration', function (): void {
    $alpha = issueRegistration('Capability Alpha', 'owner@capalpha.test');
    $beta = issueRegistration('Capability Beta', 'owner@capbeta.test');

    $this->withHeaders($this->registrationHeaders($beta['access_token']))
        ->getJson("/api/v1/public/registrations/{$alpha['registration']->uuid}")
        ->assertStatus(404);
});

it('accepts the correct token', function (): void {
    $issued = issueRegistration();

    $this->withHeaders($this->registrationHeaders($issued['access_token']))
        ->getJson("/api/v1/public/registrations/{$issued['registration']->uuid}")
        ->assertOk()
        ->assertJsonPath('data.uuid', $issued['registration']->uuid)
        ->assertJsonPath('data.status', 'preparing');
});

it('retries with the correct token', function (): void {
    $issued = issueRegistration();
    $token = $issued['access_token'];

    failIssuedRegistration($issued['registration']);

    $this->withHeaders($this->registrationHeaders($token))
        ->postJson("/api/v1/public/registrations/{$issued['registration']->uuid}/retry")
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');

    $this->runProvisioning($issued['registration']);

    expect($issued['registration']->refresh()->status)->toBe(RegistrationStatus::Ready);
});

/*
|--------------------------------------------------------------------------
| Only the hash is persisted
|--------------------------------------------------------------------------
*/

it('persists only the hash, never the plaintext token', function (): void {
    $issued = issueRegistration();
    $plaintext = $issued['access_token'];

    expect($plaintext)->toHaveLength(64);

    $row = DB::connection('control')->table('registrations')
        ->where('uuid', $issued['registration']->uuid)->first();

    expect($row->access_token_hash)->toBe(hash('sha256', $plaintext))
        ->and($row->access_token_hash)->not->toBe($plaintext);

    // And the plaintext appears in no column of any durable control table.
    foreach (['registrations', 'jobs', 'failed_jobs', 'platform_audit_logs', 'tenant_operations'] as $table) {
        foreach (DB::connection('control')->table($table)->get() as $record) {
            foreach ((array) $record as $value) {
                if (is_scalar($value)) {
                    expect((string) $value)->not->toContain($plaintext);
                }
            }
        }
    }
});

it('keeps the token out of logs, audit and queue payloads across the whole flow', function (): void {
    $lines = [];

    Log::listen(function ($message) use (&$lines): void {
        $lines[] = $message->message.' '.json_encode($message->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    $issued = issueRegistration('Logged Capability', 'owner@logcap.test');
    $plaintext = $issued['access_token'];

    failIssuedRegistration($issued['registration']);

    $this->withHeaders($this->registrationHeaders($plaintext))
        ->postJson("/api/v1/public/registrations/{$issued['registration']->uuid}/retry")
        ->assertOk();

    $this->runProvisioning($issued['registration']);

    foreach ($lines as $line) {
        expect($line)->not->toContain($plaintext);
    }

    // The queue payload still carries a uuid and nothing else.
    foreach (DB::connection('control')->table('jobs')->get() as $job) {
        expect((string) $job->payload)->not->toContain($plaintext);
    }

    foreach (DB::connection('control')->table('platform_audit_logs')->get() as $entry) {
        foreach ((array) $entry as $value) {
            if (is_scalar($value)) {
                expect((string) $value)->not->toContain($plaintext);
            }
        }
    }
});

it('never serialises the token hash', function (): void {
    $issued = issueRegistration();

    $serialised = [
        $issued['registration']->toJson(),
        json_encode($issued['registration']->toArray(), JSON_THROW_ON_ERROR),
        json_encode($issued['registration']->toStatusPayload(), JSON_THROW_ON_ERROR),
    ];

    foreach ($serialised as $output) {
        expect($output)->not->toContain('access_token_hash')
            ->not->toContain($issued['access_token']);
    }
});

it('does not reissue a capability for a repeated submission', function (): void {
    $payload = [
        'center_name' => 'Repeat Center',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@repeat.test',
        'password' => CAPABILITY_PLAINTEXT,
    ];

    $first = $this->postJson('/api/v1/public/registrations', $payload)->assertStatus(202);
    $second = $this->postJson('/api/v1/public/registrations', $payload)->assertStatus(202);

    // Otherwise anyone who can guess the idempotency inputs — a center name and
    // an email address — could mint themselves a capability for a registration
    // they do not own.
    expect($first->json('data.access_token'))->toBeString()
        ->and($second->json('data.access_token'))->toBeNull()
        ->and(Registration::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The capability expires with the registration
|--------------------------------------------------------------------------
*/

it('allows a short read-only grace after the center is ready', function (): void {
    $result = $this->registerCenter('Grace Center', 'owner@grace.test', CAPABILITY_PLAINTEXT);

    $registration = $result['registration']->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Ready)
        ->and($registration->access_token_hash)->not->toBeNull()
        ->and($registration->access_expires_at)->not->toBeNull();

    // The reason the grace exists: this poll is how the owner learns the key.
    $this->withHeaders($this->registrationHeaders($result['access_token']))
        ->getJson("/api/v1/public/registrations/{$registration->uuid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ready');
});

it('cannot retry a ready registration even inside the read grace', function (): void {
    $result = $this->registerCenter('Ready Center', 'owner@readycap.test', CAPABILITY_PLAINTEXT);

    $this->withHeaders($this->registrationHeaders($result['access_token']))
        ->postJson("/api/v1/public/registrations/{$result['registration']->uuid}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'REGISTRATION.NOT_RETRYABLE');

    // Structural, not a status check: retry requires `failed`, and `ready`
    // never returns to it.
    expect($result['registration']->refresh()->isRetryable())->toBeFalse();
});

it('stops honouring the token once the read grace has elapsed', function (): void {
    $result = $this->registerCenter('Elapsed Center', 'owner@elapsed.test', CAPABILITY_PLAINTEXT);

    $result['registration']->forceFill(['access_expires_at' => Carbon::now()->subMinute()])->save();

    $this->withHeaders($this->registrationHeaders($result['access_token']))
        ->getJson("/api/v1/public/registrations/{$result['registration']->uuid}")
        ->assertStatus(404);
});

it('destroys the capability when a registration is cancelled', function (): void {
    $issued = issueRegistration('Cancelled Capability', 'owner@cancelcap.test');

    app(RegistrationService::class)->cancel($issued['registration'], 'requested by owner');

    $registration = $issued['registration']->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Cancelled)
        ->and($registration->access_token_hash)->toBeNull();

    $this->withHeaders($this->registrationHeaders($issued['access_token']))
        ->getJson("/api/v1/public/registrations/{$registration->uuid}")
        ->assertStatus(404);
});

it('destroys the capability when a registration is abandoned', function (): void {
    $issued = issueRegistration('Abandoned Capability', 'owner@abandoncap.test');

    failIssuedRegistration($issued['registration']);

    $issued['registration']->forceFill(['credentials_expire_at' => Carbon::now()->subHour()])->save();

    expect(app(RegistrationService::class)->sweepAbandoned())->toBe(1);

    $registration = $issued['registration']->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Abandoned)
        ->and($registration->access_token_hash)->toBeNull();

    $this->withHeaders($this->registrationHeaders($issued['access_token']))
        ->postJson("/api/v1/public/registrations/{$registration->uuid}/retry")
        ->assertStatus(404);
});

it('sweeps capabilities whose read grace has passed', function (): void {
    $result = $this->registerCenter('Swept Capability', 'owner@sweptcap.test', CAPABILITY_PLAINTEXT);

    $result['registration']->forceFill(['access_expires_at' => Carbon::now()->subMinute()])->save();

    expect(app(RegistrationService::class)->sweepExpiredAccessTokens())->toBe(1)
        ->and($result['registration']->refresh()->access_token_hash)->toBeNull()
        // The registration itself is untouched: it succeeded.
        ->and($result['registration']->refresh()->status)->toBe(RegistrationStatus::Ready);
});

/*
|--------------------------------------------------------------------------
| The web surface
|--------------------------------------------------------------------------
*/

it('shows the status page only to the browser holding the capability', function (): void {
    $issued = issueRegistration('Web Capability', 'owner@webcap.test');

    // No session entry: a stranger who was sent the link.
    Livewire::test(RegistrationStatusPage::class, ['uuid' => $issued['registration']->uuid])
        ->assertOk()
        ->assertSee('could not find that registration')
        ->assertDontSee('Web Capability');

    session()->put(RegistrationSession::keyFor($issued['registration']->uuid), $issued['access_token']);

    Livewire::test(RegistrationStatusPage::class, ['uuid' => $issued['registration']->uuid])
        ->assertOk()
        ->assertDontSee('could not find that registration');

    $this->runProvisioning($issued['registration']);
    $this->trackRegistrationDatabase($issued['registration']);
});

it('will not retry from the status page without the capability', function (): void {
    $issued = issueRegistration('Web Retry Capability', 'owner@webretrycap.test');

    failIssuedRegistration($issued['registration']);

    $queuedBefore = DB::connection('control')->table('jobs')->count();

    Livewire::test(RegistrationStatusPage::class, ['uuid' => $issued['registration']->uuid])
        ->call('retry');

    expect(DB::connection('control')->table('jobs')->count())->toBe($queuedBefore)
        ->and($issued['registration']->refresh()->status)->toBe(RegistrationStatus::Failed);
});

/*
|--------------------------------------------------------------------------
| Throttling still applies
|--------------------------------------------------------------------------
|
| The capability check must not have become a way around the rate limiter: a
| valid token still enqueues provisioning work, which is the expensive thing.
|
*/

it('still throttles retry attempts that carry a valid token', function (): void {
    $issued = issueRegistration('Throttled Capability', 'owner@throttlecap.test');
    $token = $issued['access_token'];

    failIssuedRegistration($issued['registration']);

    $url = "/api/v1/public/registrations/{$issued['registration']->uuid}/retry";
    $headers = $this->registrationHeaders($token);

    // The submit limiter is 10/hour by IP.
    $statuses = [];

    for ($i = 0; $i < 12; $i++) {
        $statuses[] = $this->withHeaders($headers)->postJson($url)->status();
    }

    expect($statuses)->toContain(429);
});

it('still throttles unauthorised attempts, so the 404 is not a free probe', function (): void {
    $uuid = Str::uuid()->toString();
    $headers = $this->registrationHeaders(RegistrationAccessToken::generate());

    $statuses = [];

    for ($i = 0; $i < 12; $i++) {
        $statuses[] = $this->withHeaders($headers)->postJson("/api/v1/public/registrations/{$uuid}/retry")->status();
    }

    // Otherwise the capability check would be brute-forceable at line speed.
    expect($statuses)->toContain(429);
});

afterEach(function (): void {
    RateLimiter::clear('registration');
    $this->tearDownRegisteredCenters();
});
