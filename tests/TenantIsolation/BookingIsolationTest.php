<?php

declare(strict_types=1);

use App\Kernel\Http\Middleware\EnsureIdempotency;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Booking, across tenants
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §4 · Phase 6 §41.
|
| A release gate. Bookings are the busiest write path in the product and the
| first one two centers will exercise simultaneously, so "Center A's calendar
| never contains Center B's appointments" has to be proved rather than assumed.
|
*/

const ISOLATION_DATE = '2026-10-14';

it('keeps two centers\' appointments entirely separate', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    foreach ([$alpha, $beta] as $index => $center) {
        $this->asCenter($center['tenant'], function () use ($index): void {
            $seed = $this->seedBookableCenter();

            app(BookingEngine::class)->book(
                new BookingRequest(
                    branchUuid: $seed['branch']->uuid,
                    lines: [new BookingLine($seed['service']->uuid)],
                    startsAt: $this->localTime($seed['branch'], ISOLATION_DATE, '10:00'),
                    customer: CustomerRef::details('Customer '.$index, '+964750000000'.$index),
                ),
                BookingActor::staff($this->ownerWithCatalogAccess()),
            );
        });
    }

    $this->asCenter($alpha['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1)
            ->and(Appointment::query()->first()->customer()->value('name'))->toBe('Customer 0')
            ->and(AppointmentItem::query()->count())->toBe(1);
    });

    $this->asCenter($beta['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1)
            ->and(Appointment::query()->first()->customer()->value('name'))->toBe('Customer 1');
    });
});

it('never lets one center\'s bookings block another center\'s availability', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    // Alpha fills 10:00 with its only stylist.
    $this->asCenter($alpha['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], ISOLATION_DATE, '10:00'),
                customer: CustomerRef::details('Alpha Customer', '+9647500000001'),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        );
    });

    $this->asCenter($beta['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $slots = app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                fromDate: ISOLATION_DATE,
                toDate: ISOLATION_DATE,
            ),
            publicChannel: false,
            now: CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC'),
        );

        $times = array_map(static fn ($slot): string => $slot->localTime, $slots);

        // Beta's stylist is a different person in a different database. The two
        // centers share an employee ID SEQUENCE — both are id 1 — which is
        // exactly the shape that would break if a conflict query ever escaped
        // its connection.
        expect($times)->toContain('10:00');
    });
});

it('refuses a booking with no tenant bound rather than guessing one', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $this->seedBookableCenter();
    });

    // Outside tenant context there is no `tenant` connection at all, so the
    // query fails closed and names the model. It must never fall back to the
    // control database or inherit the previous tenant (docs/02-TENANCY.md §4).
    expect(fn (): int => Appointment::query()->count())
        ->toThrow(TenantConnectionNotInitialized::class);
});

it('leaves no tenant bound after a booking request', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $this->localTime($seed['branch'], ISOLATION_DATE, '10:00')->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Sara Ahmed',
            'customer_phone' => '0750 123 4567',
        ])->assertStatus(201);

    // A request that ends still holding a tenant is how the NEXT request reads
    // the wrong center's data.
    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('keeps idempotency keys per center, so one cannot replay another\'s booking', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $key = (string) Str::uuid();

    foreach ([$alpha, $beta] as $center) {
        $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

        $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

        // The SAME idempotency key at both centers. Keys live in the tenant
        // database, so there is nothing to collide.
        $this->withHeaders($headers + [EnsureIdempotency::HEADER => $key])
            ->postJson('/api/v1/tenant/appointments', [
                'branch' => $seed['branch']->uuid,
                'starts_at' => $this->localTime($seed['branch'], ISOLATION_DATE, '10:00')->toIso8601String(),
                'services' => [['service' => $seed['service']->uuid]],
                'customer_name' => 'Sara Ahmed',
                'customer_phone' => '0750 123 4567',
            ])->assertStatus(201);

        $this->app['auth']->forgetGuards();
    }

    foreach ([$alpha, $beta] as $center) {
        $this->asCenter($center['tenant'], function (): void {
            expect(Appointment::query()->count())->toBe(1)
                ->and(DB::connection('tenant')->table('idempotency_keys')->count())->toBe(1);
        });
    }
});

it('never serves one center\'s appointment through another center\'s token', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $uuid = $this->asCenter($alpha['tenant'], function (): string {
        $seed = $this->seedBookableCenter();

        return app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], ISOLATION_DATE, '10:00'),
                customer: CustomerRef::details('Alpha Customer', '+9647500000001'),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->uuid;
    });

    $betaHeaders = $this->tokenHeaders($this->apiTokenFor($beta['tenant']));

    // A record in another tenant is 404, never 403: existence is not disclosed
    // across tenants (docs/08-AUDIT-SECURITY.md §19).
    $this->withHeaders($betaHeaders)
        ->getJson("/api/v1/tenant/appointments/{$uuid}")
        ->assertNotFound();
});

it('never serves one center\'s public availability from another center\'s key', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $alphaSeed = $this->asCenter($alpha['tenant'], function (): array {
        $seed = $this->seedBookableCenter();
        $this->publishMenu();

        return $seed;
    });

    $this->asCenter($beta['tenant'], function (): void {
        $this->seedBookableCenter();
        $this->publishMenu();
    });

    $betaKey = $this->publicKeyOf($beta['tenant']);

    // Beta's public key with ALPHA's branch uuid. The tenant comes from the
    // path segment and the branch is looked up inside that tenant, so the uuid
    // simply is not there (ADR-036).
    $this->getJson("/api/v1/menu/{$betaKey}/availability?".http_build_query([
        'branch' => $alphaSeed['branch']->uuid,
        'from' => ISOLATION_DATE,
        'services' => [['service' => $alphaSeed['service']->uuid]],
    ]))->assertStatus(422)->assertJsonPath('error.code', 'BOOKING.POLICY_VIOLATION');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
