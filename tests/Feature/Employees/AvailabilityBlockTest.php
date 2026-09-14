<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Availability\BlockFinder;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Employees\Application\Actions\SaveAvailabilityBlock;
use App\Modules\Employees\Domain\Models\EmployeeAvailabilityBlock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| Employee availability blocks
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §§13, 14, 39, 45, 51.
|
| Phase 6 knew about appointments and nothing else, so a stylist's lunch hour
| was bookable. This is the smallest thing that fixes that — and it is NOT an
| attendance module.
|
*/

function abDate(): string
{
    return CarbonImmutable::now()->addDays(23)->format('Y-m-d');
}

function abBlock(array $seed, string $from, string $to, ?string $employeeUuid = null): EmployeeAvailabilityBlock
{
    return app(SaveAvailabilityBlock::class)([
        'employee' => $employeeUuid ?? $seed['employee']->uuid,
        'branch' => $seed['branch']->uuid,
        'starts_at' => abDate().' '.$from,
        'ends_at' => abDate().' '.$to,
        'type' => 'break',
    ], test()->ownerWithCatalogAccess());
}

function abSlots(array $seed): array
{
    return array_map(
        static fn ($slot): string => $slot->localTime,
        app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery(
                branchUuid: $seed['branch']->uuid,
                fromDate: abDate(),
                toDate: abDate(),
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
            ),
            false,
        ),
    );
}

function abBook(array $seed, string $time): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], abDate(), $time),
            lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
            customer: CustomerRef::details('Sara', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    );
}

it('takes blocked time out of availability', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        expect(abSlots($seed))->toContain('12:00');

        abBlock($seed, '12:00', '13:00');

        $slots = abSlots($seed);

        // A 30-minute service starting 11:45 runs into the break; 13:00 does not.
        expect($slots)->not->toContain('12:00')
            ->and($slots)->not->toContain('11:45')
            ->and($slots)->toContain('13:00');
    });
});

it('does not treat an adjacent block as a conflict', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        abBlock($seed, '12:00', '13:00');

        // The half-open rule again: a break ending at 13:00 does not block a
        // 13:00 appointment. Getting this wrong in the other direction would
        // silently lose a slot every day.
        expect(abBook($seed, '13:00'))->toBeInstanceOf(Appointment::class)
            ->and(fn (): Appointment => abBook($seed, '12:30'))->toThrow(BookingFailed::class);
    });
});

it('blocks one employee without touching another', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $second = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        abBlock($seed, '12:00', '13:00');

        // Ahmed is on a break; Sara is not, so the slot survives.
        expect(abSlots($seed))->toContain('12:00');

        abBlock($seed, '12:00', '13:00', $second->uuid);

        expect(abSlots($seed))->not->toContain('12:00');
    });
});

it('refuses a block at a branch the employee does not work at', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $elsewhere = $this->seedBranch('Mansour');

        // A block at a branch somebody does not work at blocks nothing, so it
        // is a mistake worth naming rather than a row worth writing.
        expect(fn (): EmployeeAvailabilityBlock => app(SaveAvailabilityBlock::class)([
            'employee' => $seed['employee']->uuid,
            'branch' => $elsewhere->uuid,
            'starts_at' => abDate().' 12:00',
            'ends_at' => abDate().' 13:00',
        ], $this->ownerWithCatalogAccess()))->toThrow(ValidationException::class);
    });
});

it('leaves an existing booking alone and surfaces it instead', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = abBook($seed, '12:00');

        abBlock($seed, '12:00', '13:00');

        // NOT cancelled, NOT moved. Rescheduling somebody's customer is a
        // decision the center makes (§14).
        expect($appointment->fresh()?->status->value)->toBe('booked')
            ->and($appointment->fresh()?->starts_at->format('H:i'))
            ->toBe($appointment->starts_at->format('H:i'));

        $affected = app(BlockFinder::class)->appointmentsInsideBlocks(
            (int) $seed['branch']->getKey(),
            CarbonImmutable::now(),
        );

        expect($affected)->toContain($appointment->id);
    });
});

it('interprets a block in the branch timezone', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        // Baghdad is UTC+3, so a 12:00 local block is 09:00 UTC. A block stored
        // in server-local time would remove the wrong hour of the day.
        $seed = $this->seedBookableCenter('Asia/Baghdad');

        $block = abBlock($seed, '12:00', '13:00');

        expect($block->starts_at->utc()->format('H:i'))->toBe('09:00');
    });
});

it('refuses a block without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $viewer = $this->staffWith([Permission::AppointmentView], 'viewer@alpha.test');

        expect(fn (): EmployeeAvailabilityBlock => app(SaveAvailabilityBlock::class)([
            'employee' => $seed['employee']->uuid,
            'branch' => $seed['branch']->uuid,
            'starts_at' => abDate().' 12:00',
            'ends_at' => abDate().' 13:00',
        ], $viewer))->toThrow(AuthorizationException::class, 'may not manage availability blocks');
    });
});

it('loads blocks in bulk rather than once per slot', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $count = static function (callable $work): int {
            $queries = 0;

            Event::listen(QueryExecuted::class, function () use (&$queries): void {
                $queries++;
            });

            $work();

            Event::forget(QueryExecuted::class);

            return $queries;
        };

        $baseline = $count(fn () => abSlots($seed));

        // Eight blocks across the day. The engine must still ask ONCE.
        for ($hour = 9; $hour <= 16; $hour++) {
            abBlock($seed, sprintf('%02d:10', $hour), sprintf('%02d:20', $hour));
        }

        expect($count(fn () => abSlots($seed)))->toBeLessThanOrEqual($baseline);
    });
});

it('refuses a block that ends before it starts', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        expect(fn (): EmployeeAvailabilityBlock => abBlock($seed, '13:00', '12:00'))
            ->toThrow(ValidationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
