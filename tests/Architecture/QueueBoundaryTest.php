<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The Queue boundary
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §68 · docs/04-MODULE-BOUNDARIES.md §2.
|
| Four concepts, permanently separate, and prose in a document cannot hold any
| of them:
|
|   Appointment     what was reserved
|   ServiceJourney  the operational visit
|   JourneyStage    what was actually performed
|   QueueTicket     the waiting, calling and routing around that stage
|
| The dependency points ONE WAY. Queue may read and call Journey. Journey must
| not learn Queue exists, and Booking must not learn either of them does.
|
*/

arch('booking does not depend on the queue')
    ->expect('App\Modules\Booking')
    ->not->toUse('App\Modules\Queue');

arch('the journey does not depend on the queue')
    ->expect('App\Modules\ServiceJourney')
    // The seam is the other way round: Journey states facts as domain events
    // and Queue listens. A center with no queue at all is unaffected because
    // nothing is listening (docs/17-QUEUE.md §12).
    ->not->toUse('App\Modules\Queue');

arch('resources do not depend on the queue')
    ->expect('App\Modules\Resources')
    ->not->toUse('App\Modules\Queue');

arch('the queue module does not depend on HTTP or Livewire')
    ->expect('App\Modules\Queue')
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        // A domain service takes what it needs as arguments (CLAUDE.md).
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('queue enums are string backed, so stored values survive a release')
    ->expect([
        'App\Modules\Queue\Domain\Enums\TicketState',
        'App\Modules\Queue\Domain\Enums\TicketEventType',
        'App\Modules\ServiceJourney\Domain\Enums\JourneySource',
    ])
    ->toBeStringBackedEnum();

it('never writes journey or appointment state from the queue module', function (): void {
    /*
     * THE RULE PHASE 8 RESTS ON. Journey owns execution; a queue that wrote
     * `journey_stages.status` would be a second state machine with its own idea
     * of what is legal, its own missing audit entry, and its own opinion about
     * a customer sitting in a chair (§12, correction 3).
     *
     * Starting and finishing go through Journey's Actions. Only the synchronizer
     * moves a ticket in response, and it only ever writes queue tables.
     */
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Queue/')) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $writes = preg_match(
                '/(JourneyStage|ServiceJourney|JourneyStageResource|JourneyHandoff|Appointment|AppointmentItem)'
                .'::query\(\)->(create|insert|update|delete)|'
                .'new (JourneyStage|ServiceJourney|JourneyStageResource|JourneyHandoff|Appointment|AppointmentItem)\b|'
                .'(stage|journey|appointment)->(forceFill|update|fill)\s*\(/',
                $line,
            ) === 1;

            if ($writes) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('exposes no queue Action that sets a ticket serving or completed', function (): void {
    /*
     * `serving` and `completed` are JOURNEY's to give. The state map permits
     * them from every open state because Journey can report either at any time
     * — but the ONLY writer is the synchronizer, reacting to a fact.
     *
     * A queue button that could set them would make the ticket and the stage
     * disagree, and the ticket — being the thing on the television — is the one
     * people would believe (correction 3).
     */
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Queue/Application/Actions/')) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match('/state:\s*TicketState::(Serving|Completed)/', $line) === 1) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the journey-to-queue listener synchronous', function (): void {
    /*
     * The whole consistency guarantee depends on this. A queued listener would
     * leave a stage `in_service` beside a ticket still `called` for as long as
     * a worker took — and forever on a center running no worker (correction 4).
     */
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Modules/Queue/Application/SyncTicketsWithJourney.php'
    );

    // CODE ONLY. The doc block explains at length why none of these appear,
    // which would otherwise make the scan fail on its own explanation — the
    // same reason the Phase 7 scans skip comment lines.
    $code = [];

    foreach (explode("\n", $source) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        $code[] = $line;
    }

    $code = implode("\n", $code);

    expect($code)->not->toContain('ShouldQueue')
        ->not->toContain('ShouldBroadcast')
        ->not->toContain('dispatch(')
        // Realtime delivery is always secondary and never the correctness
        // mechanism (§28, §49).
        ->not->toContain('broadcast');
});

it('routes queue work by department, never by menu category', function (): void {
    // Department is how the business is ORGANISED; Category is how the menu is
    // grouped for a customer browsing prices (ADR-037, §21).
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Queue/')) {
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

it('introduces no POS, payment or finance concept', function (): void {
    /*
     * Phase 8 is Queue. A `price`, an `invoice` or a `payment` appearing here
     * would be the start of the commerce module arriving by accident, inside a
     * phase that was scoped not to contain it.
     */
    $forbidden = ['invoice', 'payment', 'Payment', 'sale_id', 'commission', 'discount', 'tax_'];
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Queue/')) {
            continue;
        }

        foreach ($forbidden as $word) {
            if (str_contains($contents, $word)) {
                $violations[] = $path.'  '.$word;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps queue transition logic out of controllers and Livewire', function (): void {
    /*
     * A screen that flipped a ticket's state itself would be a second state
     * machine with no lock, no history row and no audit entry. Both surfaces
     * call the Actions (§21).
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
                '/(QueueTicket|QueueTicketEvent|QueueServicePoint|QueueDisplay)::query\(\)->(create|insert|update|delete)|'
                .'new (QueueTicket|QueueTicketEvent|QueueServicePoint|QueueDisplay)\b|'
                .'ticket->(forceFill|update|fill)\s*\(/',
                $line,
            ) === 1;

            if ($writes) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('uses no MySQL-only features in the Phase 8 schema', function (): void {
    // Portability (ADR-033): the schema must build identically on MariaDB 10.4
    // locally and MySQL 8 in CI.
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_09_*.php') ?: [];

    expect($migrations)->toHaveCount(4);

    foreach ($migrations as $file) {
        $contents = (string) file_get_contents($file);

        expect($contents)->not->toContain('storedAs')
            ->and($contents)->not->toContain('virtualAs')
            ->and($contents)->not->toContain('rawIndex')
            ->and($contents)->not->toContain('CAST(')
            // No CHECK constraints: the two engines disagree about enforcing
            // them, so the invariants live in the Actions and in tests.
            ->and($contents)->not->toContain('->check(')
            // And no redundant tenant column: these tables live inside the
            // tenant's own database (CLAUDE.md).
            ->and($contents)->not->toContain("'tenant_id'");
    }
});

it('stamps every queue instant as DATETIME, never TIMESTAMP', function (): void {
    /*
     * MariaDB gives the first NON-NULLABLE TIMESTAMP column in a table an
     * implicit `ON UPDATE CURRENT_TIMESTAMP`. `issued_at` and `occurred_at` are
     * those columns, and every later mutation would silently rewrite them —
     * destroying the value every waiting-time figure is measured from.
     *
     * It cost Phase 7 an afternoon on `journey_stage_resources`; it does not get
     * to cost it twice (ADR-046, ADR-050).
     */
    $file = dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_09_100004_create_queue_ticket_tables.php';
    $contents = (string) file_get_contents($file);

    foreach (explode("\n", $contents) as $index => $line) {
        if (! str_contains($line, '$table->timestamp(')) {
            continue;
        }

        // `timestamps()` — the created_at/updated_at pair — is fine and is a
        // different method.
        expect($line)->toContain('$table->timestamps(');
    }

    expect($contents)->toContain("dateTime('issued_at')")
        ->toContain("dateTime('occurred_at')");
});
