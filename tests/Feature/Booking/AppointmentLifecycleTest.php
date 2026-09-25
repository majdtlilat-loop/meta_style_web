<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Entitlements\Entitlements;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The appointment lifecycle
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§11, 12, 13, 14, 25, 32.
|
| Five statuses and an explicit transition map. Invalid moves are refused by the
| enum rather than by whoever remembered to write the check.
|
*/

const LIFECYCLE_DATE = '2026-10-14';

/**
 * @param  array<string, mixed>  $seed
 */
function bookForLifecycle(array $seed, string $at = '10:00'): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: test()->localTime($seed['branch'], LIFECYCLE_DATE, $at),
            customer: CustomerRef::details('Sara Ahmed', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

it('describes the transitions it allows, and refuses everything else', function (): void {
    // The state machine itself, with no database involved. This is the
    // specification; the tests below prove the Actions honour it.
    expect(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::Confirmed))->toBeTrue()
        ->and(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::Cancelled))->toBeTrue()
        // A walk-in served without a confirm step is real: a center that never
        // uses "confirmed" must still be able to close an appointment out.
        ->and(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::Completed))->toBeTrue()
        ->and(AppointmentStatus::Booked->canTransitionTo(AppointmentStatus::NoShow))->toBeTrue()

        ->and(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Completed))->toBeTrue()
        ->and(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::NoShow))->toBeTrue()
        ->and(AppointmentStatus::Confirmed->canTransitionTo(AppointmentStatus::Cancelled))->toBeTrue()

        // Terminal is terminal. Un-cancelling would rewrite history; a center
        // that changes its mind makes a new booking.
        ->and(AppointmentStatus::Cancelled->allowedTransitions())->toBe([])
        ->and(AppointmentStatus::Completed->allowedTransitions())->toBe([])
        ->and(AppointmentStatus::NoShow->allowedTransitions())->toBe([])

        // Only the two live states hold a slot.
        ->and(AppointmentStatus::blockingValues())->toBe(['booked', 'confirmed']);
});

it('confirms, then completes, stamping each moment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookForLifecycle($seed);

        expect($appointment->status)->toBe(AppointmentStatus::Booked)
            ->and($appointment->confirmed_at)->toBeNull();

        app(BookingEngine::class)->transition($appointment, AppointmentStatus::Confirmed, BookingActor::staff($owner));

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed)
            ->and($appointment->confirmed_at)->not->toBeNull();

        app(BookingEngine::class)->transition($appointment, AppointmentStatus::Completed, BookingActor::staff($owner));

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Completed)
            ->and($appointment->completed_at)->not->toBeNull()
            ->and($appointment->isTerminal())->toBeTrue();
    });
});

it('creates no sale, invoice or commission when an appointment is completed', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = bookForLifecycle($seed);

        // Pinned: this center owns POS, so a sale COULD be created. Completion
        // must not create one — checkout is a separate Sales Action (§12).
        expect(app(Entitlements::class)->enabled('pos'))->toBeTrue();

        app(BookingEngine::class)->transition(
            $appointment,
            AppointmentStatus::Completed,
            BookingActor::staff($this->ownerWithCatalogAccess()),
        );

        expect($appointment->fresh()?->status)->toBe(AppointmentStatus::Completed);

        // Sales exists since Phase 9, and completion wrote nothing to it.
        foreach (['sales', 'sale_items', 'invoices', 'invoice_items'] as $table) {
            expect(DB::connection('tenant')->table($table)->count())->toBe(0, "{$table} after completion");
        }

        // Finance and loyalty do not exist yet; faking their side effects would
        // leave a center's books full of records nothing can reconcile. Checked
        // one table at a time: a negated `toContain` with several needles passes
        // as soon as any ONE is missing, which proved nothing once `sales` existed.
        $tables = DB::connection('tenant')
            ->getSchemaBuilder()
            ->getTableListing();

        $names = array_map(
            static fn (string $t): string => str_contains($t, '.') ? explode('.', $t)[1] : $t,
            $tables,
        );

        foreach (['commissions', 'loyalty_points'] as $forbidden) {
            expect($names)->not->toContain($forbidden);
        }
    });
});

it('refuses an invalid transition, naming both ends', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookForLifecycle($seed);

        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($owner), 'Customer called');

        $failure = null;

        try {
            app(BookingEngine::class)->transition($appointment->refresh(), AppointmentStatus::Confirmed, BookingActor::staff($owner));
        } catch (BookingFailed $e) {
            $failure = $e;
        }

        expect($failure)->toBeInstanceOf(BookingFailed::class)
            // Actionable at a desk: "cancelled cannot become confirmed" tells
            // reception what happened; "invalid transition" does not.
            ->and($failure->getMessage())->toContain('cancelled')
            ->and($failure->getMessage())->toContain('confirmed')
            ->and($failure->errorCode()->value)->toBe('BOOKING.INVALID_TRANSITION')
            ->and($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled);
    });
});

it('records who cancelled, when, why, and from what', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookForLifecycle($seed);

        app(BookingEngine::class)->transition($appointment, AppointmentStatus::Confirmed, BookingActor::staff($owner));

        app(BookingEngine::class)->cancel($appointment->refresh(), BookingActor::staff($owner), 'Customer is ill');

        $appointment->refresh();

        expect($appointment->status)->toBe(AppointmentStatus::Cancelled)
            ->and($appointment->cancelled_at)->not->toBeNull()
            ->and($appointment->cancellation_reason)->toBe('Customer is ill')
            ->and($appointment->cancelled_by_type)->toBe('staff')
            ->and($appointment->cancelled_by_label)->toBe($owner->name)
            // "Cancelled a CONFIRMED booking" is a different event to a center
            // from cancelling a provisional one, and `status` can only hold
            // where it ended up (§13).
            ->and($appointment->cancelled_from_status)->toBe('confirmed');
    });
});

it('refuses to mark an appointment as a no-show before it has started', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // Far in the future, so "has it started" is unambiguous.
        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(10, 0)->utc(),
                customer: CustomerRef::details('Sara Ahmed', '+9647501112233'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        // A customer who has not arrived yet cannot have failed to arrive.
        // Without this guard "no-show" becomes a second, worse cancel button
        // and the history a later risk score depends on becomes noise (§14).
        expect(fn (): Appointment => app(BookingEngine::class)
            ->transition($appointment, AppointmentStatus::NoShow, BookingActor::staff($owner)))
            ->toThrow(BookingFailed::class, 'before it starts');

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Booked);
    });
});

it('marks a no-show once the appointment has started', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // Yesterday: the branch was open, the customer did not come.
        $appointment = bookForLifecycle($seed);
        $appointment->forceFill([
            'starts_at' => CarbonImmutable::now()->subHours(2),
            'ends_at' => CarbonImmutable::now()->subHours(1),
        ])->save();

        app(BookingEngine::class)->transition($appointment, AppointmentStatus::NoShow, BookingActor::staff($owner));

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow)
            ->and($appointment->no_show_at)->not->toBeNull()
            ->and($appointment->blocksTime())->toBeFalse();
    });
});

it('moves the whole visit when an appointment is rescheduled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $beard = $this->seedService('Beard', 20, 8000, $seed['employee']);
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid), new BookingLine($beard->uuid)],
                startsAt: $this->localTime($seed['branch'], LIFECYCLE_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '+9647509998877'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        $originalUuid = $appointment->uuid;

        app(BookingEngine::class)->reschedule(
            $appointment,
            $this->localTime($seed['branch'], LIFECYCLE_DATE, '14:00'),
            BookingActor::staff($owner),
        );

        $appointment->refresh()->load('items');
        $items = $appointment->items()->orderBy('position')->get();

        expect($appointment->localStart()->format('H:i'))->toBe('14:00')
            ->and($appointment->localEnd()->format('H:i'))->toBe('14:50')
            ->and($items[0]->starts_at->eq($appointment->starts_at))->toBeTrue()
            ->and($items[1]->starts_at->eq($items[0]->ends_at))->toBeTrue()
            // ONE appointment, not a cancel-and-rebook: the uuid is what the
            // customer's confirmation and every later reminder point at (§25).
            ->and($appointment->uuid)->toBe($originalUuid)
            ->and(Appointment::query()->count())->toBe(1)
            // Snapshots do not renegotiate because the time moved.
            ->and($items[0]->price_minor)->toBe(20000)
            ->and($items[0]->duration_minutes)->toBe(30);
    });
});

it('refuses to move a terminal appointment', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookForLifecycle($seed);

        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($owner), null);

        expect(fn (): Appointment => app(BookingEngine::class)->reschedule(
            $appointment->refresh(),
            $this->localTime($seed['branch'], LIFECYCLE_DATE, '14:00'),
            BookingActor::staff($owner),
        ))->toThrow(BookingFailed::class, 'cannot be moved');
    });
});

it('audits every schedule mutation with a comparable before and after', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = bookForLifecycle($seed);

        app(BookingEngine::class)->reschedule(
            $appointment,
            $this->localTime($seed['branch'], LIFECYCLE_DATE, '15:00'),
            BookingActor::staff($owner),
        );

        app(BookingEngine::class)->cancel($appointment->refresh(), BookingActor::staff($owner), 'Changed mind');

        $actions = TenantAuditLog::query()
            ->where('target_id', $appointment->uuid)
            ->orderBy('id')
            ->pluck('action')
            ->all();

        expect($actions)->toBe([
            'booking.appointment.created',
            'booking.appointment.rescheduled',
            'booking.appointment.cancelled',
        ]);

        $reschedule = TenantAuditLog::query()
            ->where('action', 'booking.appointment.rescheduled')
            ->firstOrFail();

        // Before and after are the same shape, so "moved from 10:00 to 15:00"
        // is directly readable — including the item windows, because a move
        // that also changed the stylist is only half told by the header times.
        expect($reschedule->before['local_start'])->toContain('10:00')
            ->and($reschedule->after['local_start'])->toContain('15:00')
            ->and($reschedule->before['items'])->toHaveCount(1)
            ->and($reschedule->after['items'][0]['starts_at'])->toBeString();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
