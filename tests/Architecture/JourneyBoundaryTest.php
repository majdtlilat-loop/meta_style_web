<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Service Journey boundary
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §55 · docs/04-MODULE-BOUNDARIES.md §2.
|
| Two rules hold this phase together, and prose in a document cannot hold
| either of them:
|
|   PLANNED and ACTUAL stay separate.  Booking says what was reserved; Journey
|   says what happened. Neither writes the other's tables.
|
|   The dependency points ONE WAY.  Journey may read Booking. Booking must not
|   learn that Journey exists, or Phase 8's queue inherits the tangle.
|
*/

arch('booking does not depend on the service journey')
    ->expect('App\Modules\Booking')
    ->not->toUse('App\Modules\ServiceJourney');

arch('resources do not depend on booking or the journey')
    ->expect('App\Modules\Resources')
    ->not->toUse([
        // Resources is a Core Records module. Booking reads it, not the other
        // way round — that is what lets the Availability Engine enforce
        // capacity without importing an operations module (ADR-049).
        'App\Modules\Booking',
        'App\Modules\ServiceJourney',
    ]);

arch('the journey module does not depend on HTTP or Livewire')
    ->expect('App\Modules\ServiceJourney')
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        // A domain service takes what it needs as arguments (CLAUDE.md).
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('journey enums are string backed, so stored values survive a release')
    ->expect([
        'App\Modules\ServiceJourney\Domain\Enums\JourneyStatus',
        'App\Modules\ServiceJourney\Domain\Enums\StageStatus',
        'App\Modules\Employees\Domain\Enums\AvailabilityBlockType',
    ])
    ->toBeStringBackedEnum();

it('never writes appointment status from the journey module', function (): void {
    /*
     * THE RULE THIS PHASE RESTS ON. The appointment lifecycle has exactly one
     * implementation — `TransitionAppointment` — and a journey that wrote
     * `appointments.status` directly would be a second one, with its own idea
     * of which transitions are legal and its own missing audit entry (§28).
     *
     * Completing and cancelling a visit both go through the Booking Action.
     */
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/ServiceJourney/')) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $writes = preg_match(
                '/Appointment(Item(Addon)?)?::query\(\)->(create|insert|update|delete)|'
                .'new Appointment(Item(Addon)?)?\b|'
                // A direct status write on an appointment model, however it got
                // hold of one.
                .'appointment->(forceFill|update|fill)\s*\(/',
                $line,
            ) === 1;

            if ($writes) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('routes operational work by department, never by menu category', function (): void {
    /*
     * Department is how the business is ORGANISED; Category is how the menu is
     * grouped for a customer browsing prices (ADR-037). A journey that routed
     * by category would send somebody to a price-list heading (§22).
     */
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/ServiceJourney/')) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match('/service_category|ServiceCategory/', $line) === 1) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps queue concepts out of the journey schema', function (): void {
    /*
     * Phase 8 builds Queue ON TOP of these tables. Putting a ticket number, a
     * position or a priority here now would be guessing at a design nobody has
     * written — and it would be the same mistake Phase 6 avoided by keeping
     * stage state off the appointment (§44).
     */
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/tenant/*journey*.php') ?: [];

    expect($migrations)->not->toBeEmpty();

    $forbidden = ['ticket', 'queue', 'priority', 'called_at', 'display', 'announce'];

    $violations = [];

    foreach ($migrations as $file) {
        foreach (explode("\n", (string) file_get_contents($file)) as $index => $line) {
            $trimmed = ltrim($line);

            // Column definitions only. The doc blocks discuss every one of
            // these words at length, on purpose.
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

it('keeps operational state off the appointment tables, in every migration', function (): void {
    /*
     * Phase 6 asserted this about its own three migrations. Phase 7 widens it
     * to EVERY tenant migration, because the way this rule actually dies is a
     * later phase adding `arrived_at` to `appointments` in a migration of its
     * own — which the original glob would never have looked at.
     */
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/tenant/*.php') ?: [];

    $forbidden = ['stage', 'queue', 'ticket', 'arrived_at', 'in_service'];
    $violations = [];

    foreach ($migrations as $file) {
        $contents = (string) file_get_contents($file);

        // Only the files that touch the booking tables.
        if (! preg_match("/(create|table)\('appointment/", $contents)) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

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

it('uses no MySQL-only index features in the Phase 7 schema', function (): void {
    // Portability (ADR-033): the schema must build identically on MariaDB
    // locally and MySQL 8 in CI.
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_08_*.php') ?: [];

    expect($migrations)->toHaveCount(5);

    foreach ($migrations as $file) {
        $contents = (string) file_get_contents($file);

        expect($contents)->not->toContain('storedAs')
            ->and($contents)->not->toContain('virtualAs')
            ->and($contents)->not->toContain('rawIndex')
            ->and($contents)->not->toContain('CAST(')
            // And no redundant tenant column: these tables live inside the
            // tenant's own database (CLAUDE.md).
            ->and($contents)->not->toContain("'tenant_id'");
    }
});

it('keeps journey transition logic out of controllers and Livewire', function (): void {
    /*
     * A screen that flipped a stage's status itself would be a second state
     * machine with no audit entry, no resource release and no branch check.
     * Both surfaces call the Actions (§55).
     */
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Http/') && ! str_starts_with($path, 'app/Livewire/')) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $writes = preg_match(
                '/(JourneyStage|ServiceJourney|JourneyStageResource|JourneyHandoff)::query\(\)->(create|insert|update|delete)|'
                .'new (JourneyStage|ServiceJourney|JourneyStageResource|JourneyHandoff)\b/',
                $line,
            ) === 1;

            if ($writes) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});
