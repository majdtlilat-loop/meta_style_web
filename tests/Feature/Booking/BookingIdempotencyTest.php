<?php

declare(strict_types=1);

use App\Kernel\Http\Middleware\EnsureIdempotency;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Idempotency
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §29 · docs/10-API-FOUNDATION.md §6.
|
| A phone on a bad connection retries. A customer taps confirm twice. Without
| replay protection each of those is a second appointment holding a second slot,
| and by Phase 9 a second invoice.
|
*/

const IDEMPOTENCY_DATE = '2026-10-14';

/**
 * @param  array<string, mixed>  $seed
 * @return array<string, mixed>
 */
function bookingPayload(array $seed, string $at = '10:00'): array
{
    return [
        'branch' => $seed['branch']->uuid,
        'starts_at' => test()->localTime($seed['branch'], IDEMPOTENCY_DATE, $at)->toIso8601String(),
        'services' => [['service' => $seed['service']->uuid]],
        'customer_name' => 'Sara Ahmed',
        'customer_phone' => '0750 123 4567',
    ];
}

it('replays the stored response for a repeated request', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $payload = $this->asCenter($center['tenant'], fn (): array => bookingPayload($this->seedBookableCenter()));

    $key = (string) Str::uuid();

    $first = $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments', $payload)
        ->assertStatus(201);

    $second = $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments', $payload)
        ->assertStatus(201);

    expect($second->json('data.uuid'))->toBe($first->json('data.uuid'))
        // A replay is announced, so a client debugging a retry storm can tell
        // the difference. Harmless to expose.
        ->and($second->headers->get('Idempotent-Replay'))->toBe('true');

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1)
            // And no duplicate customer either.
            ->and(Customer::query()->count())->toBe(1);
    });
});

it('refuses the same key with a different payload', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $key = (string) Str::uuid();

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed, '10:00'))
        ->assertStatus(201);

    // Same key, a different time. Replaying the stored answer would tell the
    // client "your 11:00 booking succeeded" when what exists is the 10:00 one.
    $conflict = $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed, '11:00'))
        ->assertStatus(409);

    expect($conflict->json('error.code'))->toBe('IDEMPOTENCY.CONFLICT');

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1);
    });
});

it('requires the header on booking creation', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    // An optional header is one every client forgets, and the clients that
    // forget are the ones on unreliable connections that need it most.
    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed))
        ->assertStatus(422);

    expect($response->json('error.details.header'))->toBe('Idempotency-Key');
});

it('lets a different key create a second, genuinely different booking', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed, '10:00'))
        ->assertStatus(201);

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed, '11:00'))
        ->assertStatus(201);

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(2);
    });
});

it('scopes a key to its endpoint, so one key can be reused elsewhere', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $key = (string) Str::uuid();

    $created = $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed))
        ->assertStatus(201);

    // The SAME key against a different operation is a different record, not a
    // false replay of the booking.
    $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments/'.$created->json('data.uuid').'/reschedule', [
            'starts_at' => $this->localTime($seed['branch'], IDEMPOTENCY_DATE, '14:00')->toIso8601String(),
        ])
        ->assertStatus(200);

    $this->asCenter($center['tenant'], function (): void {
        expect(DB::connection('tenant')->table('idempotency_keys')->count())->toBe(2);
    });
});

it('does not hold a key hostage after a failed request', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $key = (string) Str::uuid();

    // 08:00 — before the branch opens.
    $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed, '08:00'))
        ->assertStatus(422);

    // The client fixes the time and retries with the same key. Holding the key
    // would turn one bad request into a permanently unusable one — the failure
    // mode where the safety mechanism becomes the outage.
    $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed, '10:00'))
        ->assertStatus(201);

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1);
    });
});

it('sweeps expired keys and leaves live ones alone', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', bookingPayload($seed))
        ->assertStatus(201);

    $this->asCenter($center['tenant'], function (): void {
        DB::connection('tenant')->table('idempotency_keys')->insert([
            'key' => 'stale',
            'endpoint' => 'api.tenant.appointments.store',
            'request_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'response_code' => 201,
            'expires_at' => CarbonImmutable::now()->subDay(),
            'created_at' => CarbonImmutable::now()->subDays(2),
            'updated_at' => CarbonImmutable::now()->subDays(2),
        ]);

        expect(DB::connection('tenant')->table('idempotency_keys')->count())->toBe(2);
    });

    // A table that grows by one row per booking forever is a slow leak, so the
    // sweep is a real command rather than a comment.
    $this->artisan('metastyle:idempotency:sweep')->assertSuccessful();

    $this->asCenter($center['tenant'], function (): void {
        expect(DB::connection('tenant')->table('idempotency_keys')->count())->toBe(1);
    });
});

it('schedules the sweep hourly, without overlap, on one server', function (): void {
    /*
     * A command nothing runs is a comment. Expired keys hold the RESPONSE BODY
     * they replay, so how long they linger is retention as much as
     * housekeeping: hourly bounds a 24-hour key at about 25 hours, where a
     * daily pass would allow nearly 48.
     */
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'metastyle:idempotency:sweep'));

    expect($events)->toHaveCount(1);

    /** @var Event $sweep */
    $sweep = $events->first();

    expect($sweep->expression)->toBe('0 * * * *')
        // The pass walks every center; a slow run must not be joined by the
        // next hour's.
        ->and($sweep->withoutOverlapping)->toBeTrue()
        // And every worker must not sweep the same centers simultaneously.
        ->and($sweep->onOneServer)->toBeTrue();
});

it('sweeps the centers it can reach when one of them is broken', function (): void {
    $healthy = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $broken = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($healthy['tenant'], function (): void {
        DB::connection('tenant')->table('idempotency_keys')->insert([
            'key' => 'stale',
            'endpoint' => 'api.tenant.appointments.store',
            'request_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'response_code' => 201,
            'expires_at' => CarbonImmutable::now()->subDay(),
            'created_at' => CarbonImmutable::now()->subDays(2),
            'updated_at' => CarbonImmutable::now()->subDays(2),
        ]);
    });

    // Beta's database goes missing — restored from a snapshot, mid-migration,
    // simply gone. Ordered before Alpha so the failure comes FIRST: a sweep
    // that aborts on it would leave Alpha unswept and the test would say so.
    TenantModel::query()->whereKey($broken['tenant']->id)
        ->update(['sequence' => 0, 'tenancy_db_name' => 'metastyle_no_such_database']);

    /*
     * Non-zero, because a pass that silently skipped part of the platform is
     * not a success and an operator has to be able to see that. But it still
     * finished the rest: the scheduler runs this across every center, and one
     * unreachable database must not stall the others (docs/02-TENANCY.md §7).
     */
    $this->artisan('metastyle:idempotency:sweep')->assertFailed();

    $this->asCenter($healthy['tenant'], function (): void {
        expect(DB::connection('tenant')->table('idempotency_keys')->count())->toBe(0);
    });
});

it('protects the public guest booking endpoint too', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $payload = [
        'branch' => $seed['branch']->uuid,
        'starts_at' => $this->localTime($seed['branch'], IDEMPOTENCY_DATE, '10:00')->toIso8601String(),
        'services' => [['service' => $seed['service']->uuid]],
        'name' => 'Sara Ahmed',
        'phone' => '0750 123 4567',
    ];

    $idempotencyKey = (string) Str::uuid();

    $first = $this->withHeaders(['Accept' => 'application/json', EnsureIdempotency::HEADER => $idempotencyKey])
        ->postJson("/api/v1/menu/{$key}/bookings", $payload)
        ->assertStatus(201);

    $second = $this->withHeaders(['Accept' => 'application/json', EnsureIdempotency::HEADER => $idempotencyKey])
        ->postJson("/api/v1/menu/{$key}/bookings", $payload)
        ->assertStatus(201);

    expect($second->json('data.uuid'))->toBe($first->json('data.uuid'));

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
