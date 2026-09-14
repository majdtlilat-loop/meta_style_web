<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Enums\SubscriptionStatus;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\Tenancy\Enums\TenantStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Onboarding\Application\RegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Self-registration
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 3, Part C.
|
*/

it('creates exactly one pending registration and provisions a working center', function (): void {
    $result = $this->registerCenter('Barbershop Alpha');

    expect($result['registration']->refresh()->status)->toBe(RegistrationStatus::Ready);

    $tenant = TenantModel::query()->findOrFail($result['tenant']->id);

    expect($tenant->status)->toBe(TenantStatus::Active->value)
        ->and(TenantModel::query()->count())->toBe(1);

    // The center is genuinely usable: main branch, owner, roles.
    $this->asCenter($result['tenant'], function (): void {
        expect(Branch::query()->where('is_main', true)->count())->toBe(1)
            ->and(User::query()->where('is_owner', true)->count())->toBe(1)
            ->and(DB::connection('tenant')->table('roles')->count())->toBe(5);
    });
});

it('does not create a second center when the same registration is retried', function (): void {
    $first = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test', 'correct-horse-battery-staple', 'idem-1');
    $second = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test', 'correct-horse-battery-staple', 'idem-1');

    expect($second['registration']->uuid)->toBe($first['registration']->uuid)
        ->and(Registration::query()->count())->toBe(1)
        ->and(TenantModel::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1);
});

it('derives an idempotency key from the submission when the client sends none', function (): void {
    $payload = [
        'center_name' => 'Laser Center Beta',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@beta.test',
        'password' => 'correct-horse-battery-staple',
    ];

    $this->postJson('/api/v1/public/registrations', $payload)->assertStatus(202);
    $this->postJson('/api/v1/public/registrations', $payload)->assertStatus(202);

    // A double-clicked submit must not produce two centers.
    expect(Registration::query()->count())->toBe(1);
});

it('reports preparing before provisioning runs, and never success early', function (): void {
    $response = $this->postJson('/api/v1/public/registrations', [
        'center_name' => 'Spa Gamma',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@gamma.test',
        'password' => 'correct-horse-battery-staple',
    ])->assertStatus(202);

    $uuid = $response->json('data.uuid');
    $token = $response->json('data.access_token');

    expect($response->json('data.status'))->toBe('preparing')
        ->and($response->json('data.tenant'))->toBeNull()
        // Returned exactly once, here (ADR-035).
        ->and($token)->toBeString()->toHaveLength(64);

    $this->withHeaders($this->registrationHeaders((string) $token))
        ->getJson("/api/v1/public/registrations/{$uuid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'preparing');
});

it('starts a trial subscription on the default plan', function (): void {
    $result = $this->registerCenter();

    $subscription = Subscription::query()->where('tenant_id', $result['tenant']->id)->firstOrFail();

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at)->not->toBeNull()
        ->and($subscription->plan->code)->toBe('trial');
});

it('validates the submission', function (array $payload, string $field): void {
    $this->postJson('/api/v1/public/registrations', $payload)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION.FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'no contact method' => [
        ['center_name' => 'X Center', 'owner_name' => 'Owner', 'password' => 'correct-horse-battery-staple'],
        'owner_email',
    ],
    'weak password' => [
        ['center_name' => 'X Center', 'owner_name' => 'Owner', 'owner_email' => 'a@b.test', 'password' => 'short'],
        'password',
    ],
    'missing center name' => [
        ['owner_name' => 'Owner', 'owner_email' => 'a@b.test', 'password' => 'correct-horse-battery-staple'],
        'center_name',
    ],
]);

it('marks a failed provisioning as failed and retryable, never ready', function (): void {
    $registration = app(RegistrationService::class)->register([
        'center_name' => 'Doomed Center',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@doomed.test',
        'password' => 'correct-horse-battery-staple',
    ], 'idem-doomed')['registration'];

    // Remove the default plan so provisioning cannot complete.
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);

    $this->runProvisioning($registration);

    $registration->refresh();

    expect($registration->status)->toBe(RegistrationStatus::Failed)
        ->and($registration->status->isRetryable())->toBeTrue()
        ->and($registration->error)->toBeString()->not->toBeEmpty();

    // Fix the cause and retry: it resumes rather than starting over.
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    $this->runProvisioning($registration);

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Ready)
        ->and(TenantModel::query()->count())->toBe(1);

    $this->trackRegistrationDatabase($registration);
});

/*
|--------------------------------------------------------------------------
| The public retry endpoint  (ADR-031)
|--------------------------------------------------------------------------
|
| Unauthenticated on purpose: the person whose provisioning failed has no
| account to sign in to — that is what failed. The uuid is the capability.
|
*/

it('re-queues a failed registration through the public retry endpoint', function (): void {
    $issued = app(RegistrationService::class)->register([
        'center_name' => 'Endpoint Retry Center',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@endpointretry.test',
        'password' => 'correct-horse-battery-staple',
    ], 'idem-endpoint-retry');

    $registration = $issued['registration'];

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);
    $this->runProvisioning($registration);
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    $response = $this->withHeaders($this->registrationHeaders((string) $issued['access_token']))
        ->postJson("/api/v1/public/registrations/{$registration->uuid}/retry");

    $response->assertOk()
        ->assertJsonPath('data.status', 'preparing')
        ->assertJsonPath('data.uuid', $registration->uuid);

    // 202 means "we have started", so there must actually be work queued.
    expect(DB::connection('control')->table('jobs')->where('payload', 'like', '%'.$registration->uuid.'%')->count())
        ->toBeGreaterThan(0);

    $this->runProvisioning($registration);

    expect($registration->refresh()->status)->toBe(RegistrationStatus::Ready);

    $this->trackRegistrationDatabase($registration);
});

it('refuses to retry a registration that already succeeded', function (): void {
    $result = $this->registerCenter('Done Center', 'owner@done.test');

    // Inside the read grace, so the capability still opens the row — and the
    // refusal is about state, not about access (ADR-035).
    $this->withHeaders($this->registrationHeaders($result['access_token']))
        ->postJson("/api/v1/public/registrations/{$result['registration']->uuid}/retry")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'REGISTRATION.NOT_RETRYABLE');
});

it('returns not found for an unknown registration uuid', function (): void {
    $this->withHeaders($this->registrationHeaders(str_repeat('a', 64)))
        ->postJson('/api/v1/public/registrations/'.Str::uuid()->toString().'/retry')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'RESOURCE.NOT_FOUND');
});

it('tells the client whether a registration can be retried', function (): void {
    $issued = app(RegistrationService::class)->register([
        'center_name' => 'Payload Center',
        'owner_name' => 'Owner',
        'owner_email' => 'owner@payload.test',
        'password' => 'correct-horse-battery-staple',
    ], 'idem-payload');

    $registration = $issued['registration'];
    $headers = $this->registrationHeaders((string) $issued['access_token']);

    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => false]);
    $this->runProvisioning($registration);
    DB::connection('control')->table('plans')->where('code', 'trial')->update(['is_active' => true]);

    $this->withHeaders($headers)
        ->getJson("/api/v1/public/registrations/{$registration->uuid}")
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.retryable', true);

    // And the status payload still says nothing about a credential existing.
    $body = (string) $this->withHeaders($headers)
        ->getJson("/api/v1/public/registrations/{$registration->uuid}")->getContent();

    expect($body)->not->toContain('password')
        ->not->toContain('credential')
        ->not->toContain('access_token');

    $this->trackRegistrationDatabase($registration);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
