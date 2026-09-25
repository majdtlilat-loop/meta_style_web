<?php

declare(strict_types=1);

use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Availability\AvailabilityEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Multi-service bookings
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§4, 5, 36, 41.
|
| A customer books a haircut, a beard trim and a facial as ONE visit. The engine
| has to lay them out end to end, staff each one, fit the whole block inside a
| single opening interval, and write all of it or none of it.
|
*/

const MULTI_DATE = '2026-10-14';

function multiNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC');
}

it('books three services as one visit, running back to back', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $beard = $this->seedService('Beard', 20, 8000, $seed['employee']);
        $facial = $this->seedService('Facial', 45, 30000, $seed['employee']);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [
                    new BookingLine($seed['service']->uuid),
                    new BookingLine($beard->uuid),
                    new BookingLine($facial->uuid),
                ],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '10:00'),
                customer: CustomerRef::existing($this->seedCustomer()->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        /** @var list<AppointmentItem> $items */
        $items = $appointment->items()->orderBy('position')->get()->all();

        expect($items)->toHaveCount(3)
            // ONE appointment, one arrival, one customer — which is the fact
            // reception, the queue and the invoice all need (§2).
            ->and($appointment->localStart()->format('H:i'))->toBe('10:00')
            ->and($appointment->localEnd()->format('H:i'))->toBe('11:35')

            // Each item starts exactly where the last ended.
            ->and($items[0]->starts_at->eq($appointment->starts_at))->toBeTrue()
            ->and($items[1]->starts_at->eq($items[0]->ends_at))->toBeTrue()
            ->and($items[2]->starts_at->eq($items[1]->ends_at))->toBeTrue()
            ->and($items[2]->ends_at->eq($appointment->ends_at))->toBeTrue()

            ->and($items[0]->duration_minutes)->toBe(30)
            ->and($items[1]->duration_minutes)->toBe(20)
            ->and($items[2]->duration_minutes)->toBe(45)

            // Position is the order the customer asked for.
            ->and(array_map(static fn (AppointmentItem $i): int => $i->position, $items))->toBe([0, 1, 2]);
    });
});

it('only offers times where the whole visit fits inside one opening interval', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $this->openSplit($seed['branch']);

        $beard = $this->seedService('Beard', 20, 8000, $seed['employee']);

        $slots = app(AvailabilityEngine::class)->slots(
            new AvailabilityQuery(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid), new BookingLine($beard->uuid)],
                fromDate: MULTI_DATE,
                toDate: MULTI_DATE,
            ),
            publicChannel: false,
            now: multiNow(),
        );

        $times = array_map(static fn (AvailabilitySlot $s): string => $s->localTime, $slots);

        // 50 minutes against 09:00–13:00: the last start is 12:10, which is not
        // on the 15-minute grid, so 12:00 is the last offered.
        expect($times)->toContain('12:00')
            ->and($times)->not->toContain('12:15')
            ->and($times)->not->toContain('12:30')
            ->and($times)->toContain('16:00', '21:00')
            ->and($times)->not->toContain('21:15');
    });
});

it('assigns any available employee per service, deterministically', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // A second eligible stylist, created later so their id is higher.
        $second = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '10:00'),
                customer: CustomerRef::existing($this->seedCustomer()->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        $item = $appointment->items()->first();

        // Lowest eligible id wins, and the choice is recorded as the ENGINE's
        // rather than the customer's — which is what makes a later reassignment
        // a scheduling detail instead of a phone call (§5).
        expect($item->employee_id)->toBe($seed['employee']->id)
            ->and($item->employee_id)->not->toBe($second->id)
            ->and($item->employee_selection)->toBe(EmployeeSelection::Any);
    });
});

it('records a named employee as the customer\'s own choice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $second = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid, employeeUuid: $second->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '10:00'),
                customer: CustomerRef::existing($this->seedCustomer()->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        $item = $appointment->items()->first();

        expect($item->employee_id)->toBe($second->id)
            ->and($item->employee_selection)->toBe(EmployeeSelection::Specific)
            ->and($item->wasCustomerChoice())->toBeTrue();
    });
});

it('lets one employee take several services in the same visit', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $beard = $this->seedService('Beard', 20, 8000, $seed['employee']);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid), new BookingLine($beard->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '10:00'),
                customer: CustomerRef::existing($this->seedCustomer()->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        $ids = $appointment->items()->orderBy('position')->pluck('employee_id')->all();

        // Sequential items never overlap, so the same person can do both. If
        // the engine treated its own earlier item as a conflict, this booking
        // would be impossible with one stylist.
        expect($ids)->toBe([$seed['employee']->id, $seed['employee']->id]);
    });
});

it('rejects the whole booking when one service cannot be staffed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // A second service nobody is eligible for.
        $laser = $this->seedService('Laser', 30, 50000);

        $customer = $this->seedCustomer();

        expect(fn (): Appointment => app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid), new BookingLine($laser->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '10:00'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment)->toThrow(BookingFailed::class);

        // NOTHING was written. Booking two of the three services a customer
        // asked for and silently dropping the third is worse than saying no,
        // and a half-created appointment would hold a slot nobody can see
        // (§§4, 36).
        expect(Appointment::query()->count())->toBe(0)
            ->and(DB::connection('tenant')->table('appointment_items')->count())->toBe(0);
    });
});

it('rolls the whole booking back when a write fails part way through', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $customer = $this->seedCustomer();

        // Breaks the LAST write in the sequence: the appointment inserts, its
        // item inserts, and then the add-on fails. Without one transaction
        // around all three, the center is left holding a slot for an
        // appointment whose services are half-recorded (§36).
        DB::connection('tenant')->statement(
            'ALTER TABLE appointment_item_addons DROP COLUMN price_minor'
        );

        expect(fn (): Appointment => app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid, addonUuids: [$seed['addon']->uuid])],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '10:00'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment)->toThrow(QueryException::class);

        expect(Appointment::query()->count())->toBe(0)
            ->and(DB::connection('tenant')->table('appointment_items')->count())->toBe(0);
    });
});

it('refuses a start the branch is not open for', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $customer = $this->seedCustomer();

        expect(fn (): Appointment => app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                // 08:00, an hour before opening.
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '08:00'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment)->toThrow(BookingFailed::class, 'not open');
    });
});

it('refuses a visit that would run past closing time', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $customer = $this->seedCustomer();

        expect(fn (): Appointment => app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                // 16:45 + 30 minutes = 17:15, past a 17:00 close.
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '16:45'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment)->toThrow(BookingFailed::class, 'not open');
    });
});

it('books into the small hours of a branch that opened the previous evening', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $customer = $this->seedCustomer();

        // 20:00 until 02:00. A booking at 00:30 belongs to an interval that
        // opened YESTERDAY — and a containment check against only the booking's
        // own local date finds nothing, because that day's interval does not
        // start until 20:00 (§7).
        $this->openOvernight($seed['branch']);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '00:30'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        expect($appointment->localStart()->format('H:i'))->toBe('00:30')
            ->and($appointment->localDate())->toBe(MULTI_DATE);

        // 02:30 is genuinely shut: the interval closed at 02:00, and the next
        // one does not open until 20:00.
        expect(fn (): Appointment => app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '02:30'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment)->toThrow(BookingFailed::class, 'not open');
    });
});

it('moves an appointment into the small hours of an overnight branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $customer = $this->seedCustomer();

        $this->openOvernight($seed['branch']);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '21:00'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        // Rescheduling asks the same question and must get the same answer.
        app(BookingEngine::class)->reschedule(
            $appointment,
            $this->localTime($seed['branch'], MULTI_DATE, '01:00'),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        );

        expect($appointment->refresh()->localStart()->format('H:i'))->toBe('01:00');
    });
});

it('refuses a booking for an inactive employee even when named directly', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $customer = $this->seedCustomer();

        /** @var Employee $employee */
        $employee = $seed['employee'];
        $employee->forceFill(['status' => EmployeeStatus::Inactive])->save();

        expect(fn (): Appointment => app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid, employeeUuid: $employee->uuid)],
                startsAt: $this->localTime($seed['branch'], MULTI_DATE, '10:00'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment)->toThrow(BookingFailed::class, 'not available');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
