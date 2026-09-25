<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Booking Engine boundary
|--------------------------------------------------------------------------
|
| docs/04-MODULE-BOUNDARIES.md §4.1 · docs/13-ROADMAP.md Phase 6 §§40, 45.
|
| ONE engine, and every channel is an adapter. This is the boundary the whole
| product depends on: Phases 8, 9, 13 and 14 are all adapters to it, and each
| one arrives with a deadline and an author who did not read this file.
|
| Prose in a document cannot hold that. These scans can.
|
*/

/*
 * One expectation PER NAMESPACE. With an array of targets, a negated toUse()
 * fails only when EVERY target uses the model, so one un-exempted class in one
 * namespace was hidden as long as the other namespace was clean. Checked
 * separately, any new controller or component that holds an appointment model
 * fails on its own.
 *
 * A channel that can construct these can write a booking without a lock,
 * without an availability check, without an entitlement check and without an
 * audit entry. The Manager reads bookings through Booking-module queries that
 * return plain arrays (CalendarQuery, CustomerProfileAppointments,
 * AppointmentsInsideBlocks) and never holds the model.
 *
 * READ-ONLY exemptions, each type-hinting the model to present one or to look
 * one up — never to create or mutate one. The engine remains the only writer,
 * which the source scan below enforces directly.
 */
arch('the booking engine is the only thing that writes appointments: controllers')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'App\Modules\Booking\Domain\Models\Appointment',
        'App\Modules\Booking\Domain\Models\AppointmentItem',
        'App\Modules\Booking\Domain\Models\AppointmentItemAddon',
    ])
    ->ignoring([
        'App\Http\Controllers\Api\AppointmentController',
        'App\Http\Controllers\Api\CustomerBookingController',
        'App\Http\Controllers\Api\AvailabilityBlockController',
        'App\Http\Controllers\Api\JourneyController',
    ]);

arch('the booking engine is the only thing that writes appointments: Livewire')
    ->expect('App\Livewire')
    ->not->toUse([
        'App\Modules\Booking\Domain\Models\Appointment',
        'App\Modules\Booking\Domain\Models\AppointmentItem',
        'App\Modules\Booking\Domain\Models\AppointmentItemAddon',
    ])
    ->ignoring([
        'App\Livewire\Customer\Account',
    ]);

it('never creates or updates an appointment outside the Booking module', function (): void {
    /*
     * The rule prose cannot enforce: a controller may LOOK UP an appointment,
     * and may not WRITE one. `Appointment::query()->create()` or
     * `->update([...])` anywhere outside `Modules/Booking` is a booking path
     * that skipped the lock and the audit trail.
     */
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (str_starts_with($path, 'app/Modules/Booking/')) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $writes = preg_match(
                '/Appointment(Item(Addon)?)?::query\(\)->(create|insert|update|delete)|'
                .'new Appointment(Item(Addon)?)?\b/',
                $line,
            ) === 1;

            if ($writes) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps availability computation inside the Booking module', function (): void {
    /*
     * The most valuable rule in the phase. A channel that computes its own slot
     * — "just filter the working hours here" — is a second, divergent idea of
     * when a center is open, and the two only disagree in production.
     *
     * The scan looks for the ingredients: reading branch schedules, or the
     * overlap comparison, anywhere outside the engine.
     */
    $allowed = [
        'app/Modules/Booking/',
        // Owns the schedule; it does not compute availability from it.
        'app/Modules/Branches/',
        'app/Kernel/Time/',
    ];

    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        foreach ($allowed as $prefix) {
            if (str_starts_with($path, $prefix)) {
                continue 2;
            }
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $suspicious = preg_match(
                '/BranchWorkingHour::|BranchHourException::|'
                .'workingHours\(\)->(get|where)|hourExceptions\(\)->(get|where)/',
                $line,
            ) === 1;

            if ($suspicious) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

arch('the booking module does not depend on HTTP or Livewire')
    ->expect('App\Modules\Booking')
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        // A domain service takes what it needs as arguments (CLAUDE.md).
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('the booking engine contract stays free of framework request types')
    ->expect('App\Modules\Booking\Contracts')
    ->not->toUse([
        'Illuminate\Http\Request',
        'Illuminate\Http\JsonResponse',
    ]);

arch('booking enums are string backed, so stored values survive a release')
    ->expect([
        'App\Modules\Booking\Domain\Enums\AppointmentStatus',
        'App\Modules\Booking\Domain\Enums\BookingSource',
        'App\Modules\Booking\Domain\Enums\EmployeeSelection',
    ])
    ->toBeStringBackedEnum();

it('does not smuggle Phase 7 concepts into the Phase 6 schema', function (): void {
    /*
     * The seam that makes Service Journey possible without replacing the
     * Booking Engine (§38). An appointment item says WHAT WAS RESERVED; a
     * journey stage will say what actually happened. The moment a stage status
     * or a room appears on an appointment item, the two have merged and Phase 7
     * has to migrate rather than extend.
     */
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/tenant/*appointment*.php') ?: [];

    expect($migrations)->not->toBeEmpty();

    $forbidden = [
        'stage', 'queue', 'ticket', 'room_id', 'chair', 'device',
        'invoice', 'deposit', 'payment', 'commission', 'arrived_at',
    ];

    $violations = [];

    foreach ($migrations as $file) {
        $contents = (string) file_get_contents($file);

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            // Column definitions only. The doc blocks in these files discuss
            // every one of these words at length, on purpose.
            if (! str_contains($trimmed, '$table->')) {
                continue;
            }

            foreach ($forbidden as $word) {
                if (str_contains($trimmed, $word)) {
                    $violations[] = basename($file).':'.($index + 1).'  '.trim($line);
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('uses no MySQL-only index features in the booking schema', function (): void {
    // Portability (ADR-033): the schema has to build identically on MariaDB
    // locally and MySQL 8 in CI, so no functional or generated indexes.
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_07_*.php') ?: [];

    expect($migrations)->toHaveCount(3);

    foreach ($migrations as $file) {
        $contents = (string) file_get_contents($file);

        expect($contents)->not->toContain('storedAs')
            ->and($contents)->not->toContain('virtualAs')
            ->and($contents)->not->toContain('rawIndex')
            ->and($contents)->not->toContain('CAST(');
    }
});
