<?php

declare(strict_types=1);

use App\Modules\Booking\Application\Actions\IssueVerificationCode;
use App\Modules\Booking\Application\BookingVerification;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Concurrency and double booking
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §9 — the most important correctness property in
| the phase.
|
| Two customers must not both get the same stylist at the same time. "Check
| availability, then insert" cannot guarantee that: both checks can pass before
| either insert runs. The engine's answer is a pessimistic lock on the branch
| row taken INSIDE the booking transaction, with the authoritative availability
| re-check after it.
|
| A PHP test process is single-threaded, so the tests below prove the two things
| that actually make the design work:
|
|   1. Two requests for one slot produce exactly one booking (the re-check).
|   2. The lock is a REAL database lock: a second connection cannot get past it
|      while the first holds it (the serialisation).
|
| The second is the one that matters and the one a sequential test would miss.
| It uses a genuine second connection to the same tenant database with a
| one-second lock timeout, which is as close to real concurrency as a
| single-process suite can get.
|
*/

const CONCURRENCY_DATE = '2026-10-14';

/**
 * @param  array{branch: Branch, service: mixed, employee: mixed, addon: mixed}  $seed
 */
function attemptBooking(array $seed, string $customerName): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid, employeeUuid: $seed['employee']->uuid)],
            startsAt: test()->localTime($seed['branch'], CONCURRENCY_DATE, '10:00'),
            customer: CustomerRef::details($customerName, '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

it('lets exactly one of two requests for the same slot succeed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $first = attemptBooking($seed, 'Customer A');

        // Request B, targeting the same employee at the same time.
        $failure = null;

        try {
            attemptBooking($seed, 'Customer B');
        } catch (BookingFailed $e) {
            $failure = $e;
        }

        expect($first->exists)->toBeTrue()
            ->and($failure)->toBeInstanceOf(BookingFailed::class)
            // A clear availability conflict, not a generic error: the client
            // should offer another time, and a 409 says "try again", not "you
            // did something wrong".
            ->and($failure->errorCode()->value)->toBe('BOOKING.SLOT_UNAVAILABLE')
            ->and($failure->errorCode()->httpStatus())->toBe(409)
            ->and(Appointment::query()->count())->toBe(1);
    });
});

it('lets the second request succeed at an adjacent time', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        attemptBooking($seed, 'Customer A');

        // 10:30, starting exactly when the first ends. The overlap rule is
        // strict on both sides, so this is not a conflict — and a salon
        // absolutely depends on it not being one (§10).
        $second = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid, employeeUuid: $seed['employee']->uuid)],
                startsAt: $this->localTime($seed['branch'], CONCURRENCY_DATE, '10:30'),
                customer: CustomerRef::details('Customer B', '+9647512345678'),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        expect($second->exists)->toBeTrue()
            ->and(Appointment::query()->count())->toBe(2);
    });
});

it('holds a real database lock that a second connection cannot cross', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        /** @var array<string, mixed> $config */
        $config = config('database.connections.tenant');

        // A genuinely separate connection to the same tenant database. Without
        // this the "lock" could be a no-op and every test here would still
        // pass, because one PHP process cannot contend with itself.
        config(['database.connections.tenant_probe' => $config]);

        $probe = DB::connection('tenant_probe');

        // Fail fast rather than hanging the suite for the server default.
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = null;

        DB::connection('tenant')->beginTransaction();

        try {
            // Exactly what CreateAppointment does first inside its transaction.
            Branch::query()->whereKey($seed['branch']->getKey())->lockForUpdate()->first();

            try {
                $probe->table('branches')
                    ->where('id', $seed['branch']->getKey())
                    ->lockForUpdate()
                    ->first();

                $blocked = false;
            } catch (Throwable) {
                // Lock wait timeout: the second connection could not proceed,
                // which is the serialisation the design depends on.
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

it('re-checks availability inside the transaction, not before it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Two eligible stylists, so the slot is genuinely available twice —
        // and then is not.
        $second = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        // "Any available" picks the lowest id first.
        $a = attemptAnyAvailable($seed);
        $b = attemptAnyAvailable($seed);

        expect($a->items()->first()->employee_id)->toBe($seed['employee']->id)
            ->and($b->items()->first()->employee_id)->toBe($second->id);

        // The third has nobody left, and finds that out under the lock.
        expect(fn (): Appointment => attemptAnyAvailable($seed))
            ->toThrow(BookingFailed::class);

        expect(Appointment::query()->count())->toBe(2);
    });
});

it('protects a reschedule with the same lock as a booking', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $first = attemptBooking($seed, 'Customer A');

        $second = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid, employeeUuid: $seed['employee']->uuid)],
                startsAt: $this->localTime($seed['branch'], CONCURRENCY_DATE, '14:00'),
                customer: CustomerRef::details('Customer B', '+9647512345679'),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        // Moving B onto A's time must be refused exactly as booking it would
        // be. A reschedule that skipped the check would be a double-booking
        // path with the availability engine bypassed (§25).
        expect(fn (): Appointment => app(BookingEngine::class)->reschedule(
            $second,
            $this->localTime($seed['branch'], CONCURRENCY_DATE, '10:00'),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        ))->toThrow(BookingFailed::class);

        expect($second->refresh()->localStart()->format('H:i'))->toBe('14:00')
            ->and($first->refresh()->localStart()->format('H:i'))->toBe('10:00');
    });
});

it('lets an appointment be moved onto a time it already partly occupies', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = attemptBooking($seed, 'Customer A');

        // 10:15 overlaps 10:00–10:30 — its own current window. Ignoring the
        // appointment being moved is what makes a fifteen-minute nudge
        // possible at all.
        $moved = app(BookingEngine::class)->reschedule(
            $appointment,
            $this->localTime($seed['branch'], CONCURRENCY_DATE, '10:15'),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        );

        expect($moved->localStart()->format('H:i'))->toBe('10:15')
            ->and(Appointment::query()->count())->toBe(1);
    });
});

it('leaves exactly one working code when a booking code is regenerated twice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = attemptBooking($seed, 'Customer A');

        $issue = app(IssueVerificationCode::class);

        $first = $issue->forStaff($appointment, $owner);
        $second = $issue->forStaff($appointment, $owner);

        $verification = app(BookingVerification::class);

        // Re-read, so what is checked is the digest that SURVIVED rather than
        // whatever the in-memory model happens to be carrying.
        $fresh = Appointment::query()->whereKey($appointment->getKey())->firstOrFail();

        expect($first)->not->toBe($second)
            ->and($verification->matches($fresh, $second))->toBeTrue();

        /*
         * The whole point of regenerating. A customer asks for a new code
         * precisely when they think somebody else has seen the old one, so a
         * superseded code that still opened the booking would make the feature
         * worse than useless (docs/24-BOOKING-VERIFICATION.md §8).
         */
        expect($verification->matches($fresh, $first))
            ->toBeFalse('the superseded code still opens the booking');
    });
});

it('makes a second regeneration of the same booking wait for the first', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = attemptBooking($seed, 'Customer A');
        $original = (string) $appointment->verification_code_digest;

        [$otherDesk, $release] = secondTenantConnection('tenant_code_issue');

        try {
            // Desk A is mid-regeneration: it holds the appointment row.
            $otherDesk->beginTransaction();
            $otherDesk->table('appointments')->where('id', $appointment->getKey())->lockForUpdate()->get();

            $waited = waitsForTenantLock(fn () => app(IssueVerificationCode::class)->forStaff($appointment, $owner));

            /*
             * A REAL database lock, not a check-then-write. Two people pressing
             * "regenerate" in the same second must not end with the digest of
             * one code written while the other person walks away holding the
             * other — and the only thing that can guarantee that is the row
             * lock the Action takes inside its transaction.
             */
            expect($waited)->toBeTrue()
                ->and((string) Appointment::query()
                    ->whereKey($appointment->getKey())
                    ->value('verification_code_digest'))
                ->toBe($original, 'the blocked regeneration wrote anyway');
        } finally {
            $release();
        }

        // And once the row is free again, the same call goes through.
        $issued = app(IssueVerificationCode::class)->forStaff($appointment, $owner);

        $fresh = Appointment::query()->whereKey($appointment->getKey())->firstOrFail();

        expect(app(BookingVerification::class)->matches($fresh, $issued))->toBeTrue()
            ->and((string) $fresh->verification_code_digest)->not->toBe($original);
    });
});

/**
 * @param  array<string, mixed>  $seed
 */
function attemptAnyAvailable(array $seed): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: test()->localTime($seed['branch'], CONCURRENCY_DATE, '10:00'),
            customer: CustomerRef::details('Customer '.uniqid(), '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
