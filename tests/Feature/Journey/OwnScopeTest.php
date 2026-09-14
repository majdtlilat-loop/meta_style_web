<?php

declare(strict_types=1);

use App\Kernel\Authorization\AppointmentScope;
use App\Kernel\Authorization\Permission;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Application\AppointmentScopeResolver;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\ReassignStageEmployee;
use App\Modules\ServiceJourney\Application\JourneyBoardQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Own-appointment and own-journey scope
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §30.
|
| Phase 6 deferred this and named the seam: `Employee.user_id` plus a scope
| abstraction, NEVER a role-name check — which would be wrong the first time a
| center invents a role, and invisible to the role editor.
|
*/

function osDate(): string
{
    return CarbonImmutable::now()->addDays(28)->format('Y-m-d');
}

function osBook(array $seed, $employee, string $time): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], osDate(), $time),
            lines: [new BookingLine(
                serviceUuid: $seed['service']->uuid,
                employeeUuid: $employee->uuid,
            )],
            customer: CustomerRef::details('Sara', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    );
}

it('gives the broad grant everything in scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $viewer = $this->staffWith([Permission::AppointmentView], 'wide@alpha.test');

        expect(app(AppointmentScopeResolver::class)->forViewer($viewer)->isUnrestricted())->toBeTrue();
    });
});

it('gives a linked employee only their own rows', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $stylist = $this->staffWith([Permission::AppointmentViewOwn], 'sara@alpha.test');
        $sara->forceFill(['user_id' => $stylist->getKey()])->save();

        $mine = osBook($seed, $sara, '10:00');
        $theirs = osBook($seed, $seed['employee'], '11:00');

        $visible = app(CalendarQuery::class)
            ->forRange(osDate(), osDate(), $stylist)
            ->pluck('uuid')
            ->all();

        expect($visible)->toContain($mine->uuid)
            ->and($visible)->not->toContain($theirs->uuid);
    });
});

it('shows nothing to a narrow grant with no linked employee', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        osBook($seed, $seed['employee'], '10:00');

        /*
         * A user with the own-schedule permission and NO linked employee has no
         * own work. The scope is `none()` — NOT unrestricted, which is the
         * privilege escalation a careless `?? null` would produce here.
         *
         * Refused rather than answered with an empty list: a login that should
         * have an employee record and does not is a configuration mistake, and
         * a silent empty calendar hides it for weeks.
         */
        $orphan = $this->staffWith([Permission::AppointmentViewOwn], 'orphan@alpha.test');

        $scope = app(AppointmentScopeResolver::class)->forViewer($orphan);

        expect($scope)->toBeInstanceOf(AppointmentScope::class)
            ->and($scope->isDenied())->toBeTrue()
            ->and($scope->isUnrestricted())->toBeFalse();

        expect(fn () => app(CalendarQuery::class)->forRange(osDate(), osDate(), $orphan))
            ->toThrow(AuthorizationException::class);
    });
});

it('refuses a viewer with neither grant', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $nobody = $this->staffWith([Permission::CustomerView], 'nobody@alpha.test');

        expect(fn () => app(CalendarQuery::class)->forRange(osDate(), osDate(), $nobody))
            ->toThrow(AuthorizationException::class);
    });
});

it('lets the broad grant win when a user holds both', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $both = $this->staffWith(
            [Permission::AppointmentView, Permission::AppointmentViewOwn],
            'both@alpha.test',
        );
        $sara->forceFill(['user_id' => $both->getKey()])->save();

        osBook($seed, $seed['employee'], '11:00');

        // A manager who is also a stylist must not LOSE the branch's book
        // because somebody also gave them the narrow permission.
        expect(app(CalendarQuery::class)->forRange(osDate(), osDate(), $both))->toHaveCount(1);
    });
});

it('shows a stylist the visit they are actually performing, not only the one they were booked for', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $stylist = $this->staffWith(
            [Permission::JourneyViewOwn, Permission::JourneyStageStart],
            'sara@alpha.test',
        );
        $sara->forceFill(['user_id' => $stylist->getKey()])->save();

        // Booked with Ahmed, handed to Sara mid-shift.
        $appointment = osBook($seed, $seed['employee'], '10:00');
        $owner = $this->ownerWithCatalogAccess();

        $journey = app(CheckInAppointment::class)($appointment, $owner);

        expect(app(JourneyBoardQuery::class)->forDay($stylist, osDate()))->toHaveCount(0);

        app(ReassignStageEmployee::class)($journey->stages()->first(), $sara->uuid, $owner);

        /*
         * Now it is theirs. A scope that looked only at the booked employee
         * would hide the customer they are standing in front of — the item
         * still names Ahmed, because that is what was promised (§§18, 30).
         */
        expect(app(JourneyBoardQuery::class)->forDay($stylist, osDate()))->toHaveCount(1);
    });
});

it('keeps the board out of reach without either journey grant', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $nobody = $this->staffWith([Permission::AppointmentView], 'nobody@alpha.test');

        expect(fn (): array => app(JourneyBoardQuery::class)->forDay($nobody, osDate()))
            ->toThrow(AuthorizationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
