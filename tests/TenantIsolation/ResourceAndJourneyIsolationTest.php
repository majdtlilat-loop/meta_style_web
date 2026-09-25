<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Resource and Journey isolation
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md · CLAUDE.md: anything touching tenant data needs
| a case here, and this suite is a release gate.
|
| The shape that breaks a leaky query is two centers whose rows share ids — so
| every case below builds exactly that.
|
*/

function riDate(): string
{
    return CarbonImmutable::now()->addDays(27)->format('Y-m-d');
}

function riSeedRooms(): array
{
    $seed = test()->seedBookableCenter();
    $type = test()->seedResourceType('Treatment Room');

    $seed['type'] = $type;
    $seed['room'] = test()->seedResource($type, $seed['branch'], 'Room 1', 1);

    test()->requireResource($seed['service'], $type);

    return $seed;
}

function riBook(array $seed, string $time = '10:00'): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], riDate(), $time),
            lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
            customer: CustomerRef::details('Sara', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

it('keeps two centers\' resources apart, even when their ids collide', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    foreach ([$alpha, $beta] as $center) {
        $this->asCenter($center['tenant'], function (): void {
            riSeedRooms();
        });
    }

    $names = [];

    foreach (['Alpha' => $alpha, 'Beta' => $beta] as $label => $center) {
        $names[$label] = $this->asCenter($center['tenant'], function (): array {
            /** @var list<string> $rows */
            $rows = OperationalResource::query()->pluck('name')->map(
                static fn ($name): string => (string) $name
            )->all();

            return $rows;
        });
    }

    // Each center sees exactly its own one room, whatever the ids are.
    expect($names['Alpha'])->toHaveCount(1)
        ->and($names['Beta'])->toHaveCount(1);
});

it('does not let one center\'s booking consume another\'s room capacity', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $seeds = [];

    foreach (['alpha' => $alpha, 'beta' => $beta] as $key => $center) {
        $seeds[$key] = $this->asCenter($center['tenant'], fn (): array => riSeedRooms());
    }

    // Alpha fills its only room at 10:00.
    $this->asCenter($alpha['tenant'], function () use ($seeds): void {
        riBook($seeds['alpha'], '10:00');
    });

    /*
     * Beta books the same slot. Its room has an id that may well equal Alpha's
     * — which is precisely the shape that breaks if a conflict query escapes
     * its connection.
     */
    $this->asCenter($beta['tenant'], function () use ($seeds): void {
        expect(riBook($seeds['beta'], '10:00'))->toBeInstanceOf(Appointment::class);
    });
});

it('keeps journeys and their stages inside one center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    foreach ([$alpha, $beta] as $center) {
        $this->asCenter($center['tenant'], function (): void {
            $seed = riSeedRooms();
            $appointment = riBook($seed);

            app(CheckInAppointment::class)($appointment, test()->ownerWithCatalogAccess());
        });
    }

    foreach ([$alpha, $beta] as $center) {
        $this->asCenter($center['tenant'], function (): void {
            expect(ServiceJourney::query()->count())->toBe(1)
                ->and(JourneyStage::query()->count())->toBe(1);
        });
    }
});

it('fails closed when a journey is queried with no tenant bound', function (): void {
    $this->registerCenter();

    /*
     * No fallback, no default tenant: the query FAILS rather than silently
     * hitting another center or the control database (CLAUDE.md).
     *
     * `TenantConnectionGuard` refuses before the driver is ever reached, which
     * is the earlier and better failure — the connection is never opened at
     * all.
     */
    expect(fn (): int => ServiceJourney::query()->count())
        ->toThrow(TenantConnectionNotInitialized::class);

    expect(fn (): int => OperationalResource::query()->count())
        ->toThrow(TenantConnectionNotInitialized::class);
});

it('leaves no tenant bound after operational work', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = riSeedRooms();

        app(CheckInAppointment::class)(riBook($seed), $this->ownerWithCatalogAccess());
    });

    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('writes availability blocks and stage resources into the tenant database', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = riSeedRooms();

        app(CheckInAppointment::class)(riBook($seed), $this->ownerWithCatalogAccess());

        $database = DB::connection('tenant')->getDatabaseName();

        // Every Phase 7 table lives in the CENTER's own database — which is
        // also why none of them carries a `tenant_id` column.
        foreach ([
            'resource_types',
            'resources',
            'service_resource_requirements',
            'resource_reservations',
            'employee_availability_blocks',
            'service_journeys',
            'journey_stages',
            'journey_stage_resources',
            'journey_handoffs',
        ] as $table) {
            $exists = DB::connection('tenant')->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                [$database, $table],
            );

            expect((int) ($exists->c ?? 0))->toBe(1, "missing {$table}");

            $columns = DB::connection('tenant')->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.columns
                 WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$database, $table, 'tenant_id'],
            );

            expect((int) ($columns->c ?? 0))->toBe(0, "{$table} has a redundant tenant_id");
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
