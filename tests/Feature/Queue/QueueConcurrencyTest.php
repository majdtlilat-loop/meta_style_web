<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\ChangeTicketPriority;
use App\Modules\Queue\Application\Actions\CompleteServingTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\HoldTicket;
use App\Modules\Queue\Application\Actions\ResumeTicket;
use App\Modules\Queue\Application\Actions\StartServingTicket;
use App\Modules\Queue\Application\Actions\TransferTicket;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Queue concurrency
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §§5, 35, 36, correction 5.
|
| Two desks, one Saturday. Every mutating queue Action re-reads its ticket
| `FOR UPDATE`, validates the LOCKED row, allocates the history sequence under
| that same lock, and commits — so two people pressing "call" on A012 at the
| same instant produce one call and one refusal that names what happened.
|
| A PHP test is single-threaded, so the in-transaction re-checks are proved by
| sequence and the locks themselves by a genuinely separate database connection.
|
*/

function qcSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');

    for ($i = 2; $i <= 6; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qcTicket(array $seed, User $owner, string $name = 'Sara'): QueueTicket
{
    return app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $name,
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
    )['ticket'];
}

/**
 * A genuinely separate connection with a one-second lock timeout.
 *
 * Without it, a "lock" could be a no-op and every test here would still pass:
 * one PHP process cannot contend with itself.
 *
 * @return array{0: Connection, 1: callable}
 */
function qcProbe(): array
{
    /** @var array<string, mixed> $config */
    $config = config('database.connections.tenant');

    config(['database.connections.tenant_probe' => $config]);

    $probe = DB::connection('tenant_probe');
    $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

    $release = static function () use ($probe): void {
        $probe->disconnect();
        DB::purge('tenant_probe');
    };

    return [$probe, $release];
}

it('never hands two simultaneous walk-ins the same number', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeed();

        [$probe, $release] = qcProbe();

        $blocked = null;

        DB::connection('tenant')->beginTransaction();

        try {
            // What `TicketNumbers::next()` does first: lock the sequence row.
            DB::connection('tenant')->table('queue_sequences')->insertOrIgnore([
                'branch_id' => $seed['branch']->getKey(),
                'business_date' => now()->format('Y-m-d'),
                'prefix' => 'A',
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection('tenant')->table('queue_sequences')
                ->where('branch_id', $seed['branch']->getKey())
                ->lockForUpdate()
                ->get();

            try {
                // A second desk, issuing at the same instant. It must WAIT,
                // which is the entire point — `MAX(number) + 1` would not.
                $probe->table('queue_sequences')
                    ->where('branch_id', $seed['branch']->getKey())
                    ->lockForUpdate()
                    ->get();

                $blocked = false;
            } catch (Throwable) {
                $blocked = true;
            }
        } finally {
            DB::connection('tenant')->rollBack();
            $release();
        }

        expect($blocked)->toBeTrue();
    });
});

it('serialises two desks acting on the same ticket', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qcTicket($seed, $owner);

        [$probe, $release] = qcProbe();

        $blocked = null;

        DB::connection('tenant')->beginTransaction();

        try {
            // What every mutating Action does first (correction 5).
            DB::connection('tenant')->table('queue_tickets')
                ->where('id', $ticket->getKey())
                ->lockForUpdate()
                ->get();

            try {
                $probe->table('queue_tickets')
                    ->where('id', $ticket->getKey())
                    ->lockForUpdate()
                    ->get();

                $blocked = false;
            } catch (Throwable) {
                $blocked = true;
            }
        } finally {
            DB::connection('tenant')->rollBack();
            $release();
        }

        expect($blocked)->toBeTrue();
    });
});

it('takes the ticket lock on every mutating action', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qcTicket($seed, $owner);

        $observed = [];

        $watch = function (callable $work) use (&$observed): void {
            $statements = [];

            DB::connection('tenant')->listen(function ($query) use (&$statements): void {
                $statements[] = $query->sql;
            });

            $work();

            $observed[] = (bool) array_filter(
                $statements,
                static fn (string $sql): bool => str_contains($sql, 'queue_tickets')
                    && str_contains($sql, 'for update'),
            );
        };

        $watch(fn () => app(CallTicket::class)($ticket->fresh(), $owner, $seed['reception']->uuid));
        $watch(fn () => app(HoldTicket::class)($ticket->fresh(), $owner, true));
        $watch(fn () => app(ResumeTicket::class)($ticket->fresh(), $owner));
        $watch(fn () => app(ChangeTicketPriority::class)($ticket->fresh(), 10, $owner));
        $watch(fn () => app(TransferTicket::class)(
            $ticket->fresh(),
            $owner,
            $seed['reception']->uuid,
        ));
        $watch(fn () => app(StartServingTicket::class)($ticket->fresh(), $owner));
        $watch(fn () => app(CompleteServingTicket::class)($ticket->fresh(), $owner));

        // Every one of them. A path that forgot would be the one that produces
        // a duplicate history sequence at the worst possible moment.
        expect($observed)->toBe([true, true, true, true, true, true, true]);
    });
});

it('rejects the second of two incompatible moves on one ticket', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qcTicket($seed, $owner);

        // Both desks are holding the model as it was when their page rendered.
        $deskOne = QueueTicket::query()->whereKey($ticket->getKey())->firstOrFail();
        $deskTwo = QueueTicket::query()->whereKey($ticket->getKey())->firstOrFail();

        app(CallTicket::class)($deskOne, $owner, $seed['reception']->uuid);
        app(StartServingTicket::class)($deskOne->fresh(), $owner);

        /*
         * The second desk's model still says `waiting`. The Action revalidates
         * against the LOCKED row, so it refuses rather than holding a customer
         * who is already in the chair (correction 5).
         */
        expect(fn (): QueueTicket => app(HoldTicket::class)($deskTwo, $owner))
            ->toThrow(QueueFailed::class, 'already being served');

        expect($ticket->fresh()?->state)->toBe(TicketState::Serving);
    });
});

it('keeps the history sequence contiguous under repeated mutation', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qcTicket($seed, $owner);

        for ($i = 0; $i < 6; $i++) {
            app(CallTicket::class)($ticket->fresh(), $owner, $seed['reception']->uuid);
        }

        $sequences = $ticket->events()->pluck('sequence')->all();

        expect($sequences)->toBe([1, 2, 3, 4, 5, 6, 7])
            // And the unique index is what says so even if a future path forgot
            // the lock.
            ->and(count(array_unique($sequences)))->toBe(7);
    });
});

it('lets a walk-in and a booking contend for the same room without either winning twice', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qcSeed();
        $owner = $this->ownerWithCatalogAccess();

        $type = $this->seedResourceType('Treatment Room');
        $this->seedResource($type, $seed['branch'], 'Room 1', 1);
        $this->requireResource($seed['service'], $type);

        $first = qcTicket($seed, $owner, 'First');
        $second = qcTicket($seed, $owner, 'Second');

        app(StartServingTicket::class)($first, $owner);

        /*
         * The room is genuinely taken, and the refusal comes from the Phase 7
         * combined-capacity check under the BRANCH lock — which a walk-in
         * inherits rather than reimplements (ADR-050, §16).
         */
        expect(fn (): QueueTicket => app(StartServingTicket::class)($second, $owner))
            ->toThrow(JourneyFailed::class, 'already in use');

        expect($first->fresh()?->state)->toBe(TicketState::Serving)
            ->and($second->fresh()?->state)->toBe(TicketState::Waiting);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
