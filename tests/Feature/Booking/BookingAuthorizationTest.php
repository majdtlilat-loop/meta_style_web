<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Booking\Application\Actions\ManageAppointmentNotes;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;

/*
|--------------------------------------------------------------------------
| Booking authorization
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §30 · docs/06-AUTH-ROLES-PERMISSIONS.md §5.
|
| Permission AND branch scope. Holding `appointment.create` does not mean
| holding it everywhere, and inside a tenant that is the most likely place for a
| leak.
|
*/

const AUTHZ_DATE = '2026-10-14';

/**
 * @param  array<string, mixed>  $seed
 */
function bookAs(array $seed, User $user, string $at = '10:00'): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: test()->localTime($seed['branch'], AUTHZ_DATE, $at),
            customer: CustomerRef::details('Sara Ahmed', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff($user),
    )->appointment;
}

it('adds nine appointment permissions and grants them to the right roles', function (): void {
    $codes = Permission::codes();

    expect($codes)->toContain(
        'appointment.view', 'appointment.create', 'appointment.update',
        'appointment.cancel', 'appointment.confirm', 'appointment.complete',
        'appointment.no_show', 'appointment.note.view', 'appointment.note.manage',
    );

    $owner = array_map(static fn (Permission $p): string => $p->value, SystemRole::Owner->permissions());
    $host = array_map(static fn (Permission $p): string => $p->value, SystemRole::Host->permissions());
    $cashier = array_map(static fn (Permission $p): string => $p->value, SystemRole::Cashier->permissions());
    $employee = array_map(static fn (Permission $p): string => $p->value, SystemRole::Employee->permissions());

    // Owner holds the whole catalog as EXPLICIT grants, never a bypass
    // (ADR-029).
    expect($owner)->toEqualCanonicalizing($codes)

        // Reception is the booking desk.
        ->and($host)->toContain('appointment.create', 'appointment.cancel', 'appointment.no_show')
        // …but manager-only booking notes are not theirs.
        ->and($host)->not->toContain('appointment.note.manage')

        // A cashier reads the day's book to attach a sale to the right visit,
        // and changes nothing.
        ->and($cashier)->toContain('appointment.view')
        ->and($cashier)->not->toContain('appointment.create')
        ->and($cashier)->not->toContain('appointment.cancel')

        /*
         * NARROWED IN PHASE 7, and deliberately. The role held
         * `appointment.view` — the whole branch's book — because Phase 6 had no
         * way to say "their own". It does now, and a stylist reading every
         * customer's day at the branch was always more than the role needed
         * (Phase 7 §30).
         *
         * `metastyle:roles:sync` REVOKES the broader grant on deploy (ADR-032);
         * a center that wants the old behaviour grants it in the role editor,
         * which is where that decision belongs.
         */
        ->and($employee)->toContain('appointment.view_own')
        ->and($employee)->not->toContain('appointment.view')
        ->and($employee)->not->toContain('appointment.create');
});

it('refuses to create an appointment without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Can SEE the book, cannot write in it.
        $viewer = $this->staffWith([Permission::AppointmentView, Permission::CustomerView], 'viewer@alpha.test');

        expect(fn (): Appointment => bookAs($seed, $viewer))
            ->toThrow(AuthorizationException::class, 'may not create appointments');
    });
});

it('refuses to book into a branch outside the user scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $other = $this->seedBranch('Mansour');
        $this->openEveryDay($other);
        $seed['employee']->branches()->attach($other->id);

        $host = $this->staffWith([
            Permission::AppointmentCreate,
            Permission::CustomerCreate,
            Permission::CustomerView,
        ], 'host@alpha.test');

        // Scoped to the main branch only.
        $host->forceFill(['all_branches' => false])->save();
        $host->syncBranchScope([(int) $seed['branch']->id]);
        $host->forgetPermissionCache();

        expect(fn (): Appointment => bookAs(['branch' => $other, 'service' => $seed['service']], $host))
            ->toThrow(AuthorizationException::class, 'may not work in that branch');

        // The branch they ARE scoped to still works — permission alone was
        // never the whole answer, and neither is scope alone.
        expect(bookAs($seed, $host)->exists)->toBeTrue();
    });
});

it('hides another branch\'s appointments from a scoped calendar', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $other = $this->seedBranch('Mansour');
        $this->openEveryDay($other);
        $seed['employee']->branches()->attach($other->id);

        bookAs($seed, $owner, '10:00');
        bookAs(['branch' => $other, 'service' => $seed['service']], $owner, '11:00');

        $scoped = $this->staffWith([Permission::AppointmentView], 'scoped@alpha.test');
        $scoped->forceFill(['all_branches' => false])->save();
        $scoped->syncBranchScope([(int) $seed['branch']->id]);
        $scoped->forgetPermissionCache();

        $visible = app(CalendarQuery::class)->forRange(AUTHZ_DATE, AUTHZ_DATE, $scoped);

        // The scope is in the WHERE clause, not a filter afterwards. Loading
        // everything and dropping rows in PHP would still have read them.
        expect($visible)->toHaveCount(1)
            ->and($visible->first()->branch_id)->toBe($seed['branch']->id)

            // The owner sees both.
            ->and(app(CalendarQuery::class)->forRange(AUTHZ_DATE, AUTHZ_DATE, $owner))->toHaveCount(2);
    });
});

it('refuses a named branch that is out of scope, rather than returning nothing', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $other = $this->seedBranch('Mansour');

        $scoped = $this->staffWith([Permission::AppointmentView], 'scoped2@alpha.test');
        $scoped->forceFill(['all_branches' => false])->save();
        $scoped->syncBranchScope([(int) $seed['branch']->id]);
        $scoped->forgetPermissionCache();

        // "You may not" and "there is nothing" are different answers, and at a
        // desk the difference matters.
        expect(fn () => app(CalendarQuery::class)
            ->forRange(AUTHZ_DATE, AUTHZ_DATE, $scoped, ['branch' => $other->uuid]))
            ->toThrow(AuthorizationException::class, 'may not view that branch');
    });
});

it('refuses the calendar entirely without appointment.view', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();

        $nobody = $this->staffWith([Permission::CustomerView], 'nobody@alpha.test');

        expect(fn () => app(CalendarQuery::class)->forRange(AUTHZ_DATE, AUTHZ_DATE, $nobody))
            ->toThrow(AuthorizationException::class, 'may not view appointments');
    });
});

it('separates booking notes from customer notes by permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookAs($seed, $owner);

        app(ManageAppointmentNotes::class)->add($appointment, 'Wants the senior stylist', $owner);
        app(ManageAppointmentNotes::class)->add($appointment, 'Disputed a charge last time', $owner, NoteVisibility::ManagerOnly);

        // Holding the CUSTOMER note permissions is not the same as holding the
        // APPOINTMENT ones. Hard-coding the customer permissions into the
        // visibility enum would have given these two the same audience the
        // moment appointments arrived (§17).
        $customerNotesOnly = $this->staffWith([
            Permission::AppointmentView,
            Permission::CustomerNoteView,
            Permission::CustomerNoteManage,
        ], 'crm@alpha.test');

        expect(app(ManageAppointmentNotes::class)->visibleTo($appointment, $customerNotesOnly))->toBe([]);

        $reader = $this->staffWith([
            Permission::AppointmentView,
            Permission::AppointmentNoteView,
        ], 'reader@alpha.test');

        // Sees the internal note, not the manager-only one.
        expect(app(ManageAppointmentNotes::class)->visibleTo($appointment, $reader))->toHaveCount(1);

        expect(app(ManageAppointmentNotes::class)->visibleTo($appointment, $owner))->toHaveCount(2);
    });
});

it('never shows staff notes in the customer view of an appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookAs($seed, $owner);

        app(ManageAppointmentNotes::class)->add($appointment, 'Difficult customer', $owner);

        $shape = app(AppointmentPresenter::class)->forCustomer(
            $appointment->load(['items', 'branch'])
        );

        // The customer shape is a separate method, not the staff shape with
        // fields removed — and what would leak is a staff note about the person
        // reading it.
        expect($shape)->not->toHaveKey('notes')
            ->and($shape)->not->toHaveKey('created_by')
            ->and($shape)->not->toHaveKey('source')
            ->and(json_encode($shape))->not->toContain('Difficult customer');
    });
});

it('refuses to write a booking note without the manage permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookAs($seed, $owner);

        $reader = $this->staffWith([Permission::AppointmentView, Permission::AppointmentNoteView], 'ro@alpha.test');

        expect(fn () => app(ManageAppointmentNotes::class)->add($appointment, 'nope', $reader))
            ->toThrow(AuthorizationException::class, 'may not write booking notes');
    });
});

it('requires the matching permission for each status change', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookAs($seed, $owner);

        // Can confirm, cannot cancel. A center that wants reception to book and
        // a manager to cancel can express exactly that.
        $confirmOnly = $this->staffWith([
            Permission::AppointmentView,
            Permission::AppointmentConfirm,
        ], 'confirm@alpha.test');

        app(BookingEngine::class)->transition($appointment, AppointmentStatus::Confirmed, BookingActor::staff($confirmOnly));

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);

        expect(fn (): Appointment => app(BookingEngine::class)
            ->cancel($appointment, BookingActor::staff($confirmOnly)))
            ->toThrow(AuthorizationException::class);
    });
});

it('refuses to move an appointment in a branch outside scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookAs($seed, $owner);

        $elsewhere = $this->staffWith([
            Permission::AppointmentView,
            Permission::AppointmentUpdate,
        ], 'else@alpha.test');

        $elsewhere->forceFill(['all_branches' => false])->save();
        $elsewhere->syncBranchScope([]);
        $elsewhere->forgetPermissionCache();

        expect(fn (): Appointment => app(BookingEngine::class)->reschedule(
            $appointment,
            $this->localTime($seed['branch'], AUTHZ_DATE, '14:00'),
            BookingActor::staff($elsewhere),
        ))->toThrow(AuthorizationException::class, 'may not work in that branch');
    });
});

it('finds every branch a user is scoped to, including ones added later', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // The owner is `all_branches`, which is a FLAG rather than a row per
        // branch — precisely so a branch created later is included without a
        // backfill.
        $later = $this->seedBranch('Opened later');
        $this->openEveryDay($later);
        $seed['employee']->branches()->attach($later->id);

        bookAs(['branch' => $later, 'service' => $seed['service']], $owner);

        expect(app(CalendarQuery::class)->forRange(AUTHZ_DATE, AUTHZ_DATE, $owner))->toHaveCount(1);

        unset($seed);
        expect(Branch::query()->active()->count())->toBe(2);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
