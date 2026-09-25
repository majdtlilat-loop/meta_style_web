<?php

declare(strict_types=1);

use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\BranchLock;
use App\Modules\Employees\Application\Actions\SaveAvailabilityBlock;
use App\Modules\Resources\Application\Actions\SaveResource;
use App\Modules\Resources\Domain\Data\ResourceInput;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Resource concurrency
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §9 and corrections §2.
|
| The branch-row lock Phase 6 introduced now protects rooms and devices too —
| and, since the Phase 7 corrections, the MUTATIONS that change capacity take
| the same lock, so a booking and a capacity change cannot both succeed against
| a world that stopped being true.
|
*/

function rcDate(): string
{
    return CarbonImmutable::now()->addDays(22)->format('Y-m-d');
}

function rcBook(array $seed, string $time): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], rcDate(), $time),
            lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
            customer: CustomerRef::details('Sara', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

function rcSeed(int $capacity = 1): array
{
    $seed = test()->seedBookableCenter();
    $type = test()->seedResourceType('Treatment Room');

    $seed['type'] = $type;
    $seed['room'] = test()->seedResource($type, $seed['branch'], 'Room 1', $capacity);

    test()->requireResource($seed['service'], $type);

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

it('lets exactly one contender take the last unit of capacity', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = rcSeed(capacity: 2);

        $succeeded = 0;
        $refused = 0;

        // Three contenders for two places. A PHP test is single-threaded, so
        // this proves the in-transaction re-check; the lock itself is proved
        // separately below with a real second connection.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                rcBook($seed, '10:00');
                $succeeded++;
            } catch (Throwable) {
                $refused++;
            }
        }

        expect($succeeded)->toBe(2)
            ->and($refused)->toBe(1)
            ->and(Appointment::query()->count())->toBe(2);
    });
});

it('makes a capacity change wait behind a booking in flight', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = rcSeed();

        /** @var array<string, mixed> $config */
        $config = config('database.connections.tenant');

        // A genuinely separate connection. Without it the "lock" could be a
        // no-op and this test would still pass, because one PHP process cannot
        // contend with itself.
        config(['database.connections.tenant_probe' => $config]);

        $probe = DB::connection('tenant_probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = null;

        DB::connection('tenant')->beginTransaction();

        try {
            // What CreateAppointment does first inside its transaction.
            DB::connection('tenant')->table('branches')
                ->where('id', $seed['branch']->getKey())
                ->lockForUpdate()
                ->get();

            try {
                // And what SaveResource does first inside ITS transaction. The
                // two must not interleave: a booking that took the last place
                // and a capacity reduction that removed it would both commit
                // (corrections §2, ADR-047).
                $probe->table('branches')
                    ->where('id', $seed['branch']->getKey())
                    ->lockForUpdate()
                    ->get();

                $blocked = false;
            } catch (Throwable) {
                $blocked = true;
            }
        } finally {
            DB::connection('tenant')->rollBack();
            $probe->disconnect();
            DB::purge('tenant_probe');
        }

        expect($blocked)->toBeTrue();
    });
});

it('takes the branch lock when a resource is saved', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = rcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $statements = [];

        DB::connection('tenant')->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(SaveResource::class)(ResourceInput::fromArray([
            'resource_type' => $seed['type']->uuid,
            'branch' => $seed['branch']->uuid,
            'name' => ['en' => 'Room 2'],
            'capacity' => 1,
        ]), $owner);

        $locked = array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'branches')
                && str_contains($sql, 'for update'),
        );

        expect($locked)->not->toBeEmpty();
    });
});

it('takes the branch lock when an availability block is written', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = rcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $statements = [];

        DB::connection('tenant')->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        app(SaveAvailabilityBlock::class)([
            'employee' => $seed['employee']->uuid,
            'branch' => $seed['branch']->uuid,
            'starts_at' => rcDate().' 12:00',
            'ends_at' => rcDate().' 13:00',
            'type' => 'break',
        ], $owner);

        $locked = array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'branches')
                && str_contains($sql, 'for update'),
        );

        expect($locked)->not->toBeEmpty();
    });
});

it('refuses to take the lock outside a transaction', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = rcSeed();

        // A row lock outside a transaction is released the instant the
        // statement finishes, so it protects nothing while looking exactly like
        // it does. Failing loudly is the only way that mistake is noticed.
        expect(fn () => app(BranchLock::class)
            ->acquireOne((int) $seed['branch']->getKey()))
            ->toThrow(RuntimeException::class, 'inside a transaction');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
