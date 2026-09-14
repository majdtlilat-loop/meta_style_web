<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\IssueTicket;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\TicketNumbers;
use App\Modules\Queue\Domain\TicketPrefix;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Human-readable queue numbers
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §6, §35.
|
| `A001`, not a uuid. Deterministic, concurrency-safe, and reset on the CENTER'S
| midnight rather than the server's.
|
*/

function qnSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qnTicket(array $seed, User $owner, array $options = []): QueueTicket
{
    $result = app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $options['branch'] ?? $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $options['name'] ?? 'Sara',
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
        ['service_point' => $options['service_point'] ?? null],
        $options['now'] ?? null,
    );

    return $result['ticket'];
}

it('numbers tickets in sequence, formatted the same way every time', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $owner = $this->ownerWithCatalogAccess();

        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $numbers[] = qnTicket($seed, $owner, ['name' => "Customer {$i}"])->display_number;
        }

        expect($numbers)->toBe(['A001', 'A002', 'A003']);
    });
});

it('keeps each branch on its own sequence', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $owner = $this->ownerWithCatalogAccess();

        $second = $this->seedBranch('Mansour');
        $this->openEveryDay($second);
        $seed['service']->branches()->detach();
        $seed['employee']->branches()->syncWithoutDetaching([$second->id]);

        $first = qnTicket($seed, $owner);
        $other = qnTicket($seed, $owner, ['branch' => $second->uuid, 'name' => 'Ali']);

        // Both are A001, and that is correct: a number belongs to a branch's
        // day, and the two waiting rooms are different rooms (§20).
        expect($first->display_number)->toBe('A001')
            ->and($other->display_number)->toBe('A001')
            ->and((int) $other->branch_id)->toBe($second->id)
            ->and(DB::connection('tenant')->table('queue_sequences')->count())->toBe(2);
    });
});

it('restarts the numbering on the branch-local day, not the server day', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $owner = $this->ownerWithCatalogAccess();

        // 21:00 UTC is already the NEXT day in Baghdad (UTC+3). A server-clock
        // reset would restart the numbering here, mid-evening, while customers
        // are still holding the earlier tickets (§6).
        $lateYesterday = CarbonImmutable::parse('2026-10-01 18:00:00', 'UTC');
        $earlyToday = CarbonImmutable::parse('2026-10-01 21:30:00', 'UTC');

        $first = qnTicket($seed, $owner, ['now' => $lateYesterday]);
        $second = qnTicket($seed, $owner, ['name' => 'Ali', 'now' => $earlyToday]);

        expect($first->display_number)->toBe('A001')
            ->and((string) $first->business_date->format('Y-m-d'))->toBe('2026-10-01')
            // A new local day, so a new sequence.
            ->and($second->display_number)->toBe('A001')
            ->and((string) $second->business_date->format('Y-m-d'))->toBe('2026-10-02');
    });
});

it('uses the service point prefix, then the department, then A', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $owner = $this->ownerWithCatalogAccess();

        $laser = $this->seedDepartment('Laser');
        $laser->forceFill(['queue_prefix' => 'L'])->save();
        $seed['service']->forceFill(['department_id' => $laser->getKey()])->save();

        // No service point: the department's letter.
        $fromDepartment = qnTicket($seed, $owner);

        expect($fromDepartment->display_number)->toBe('L001');

        // A counter with its own letter wins.
        $desk = $this->seedServicePoint($seed['branch'], 'R1', 'Reception Desk', prefix: 'R');

        $fromPoint = qnTicket($seed, $owner, ['name' => 'Ali', 'service_point' => $desk->uuid]);

        expect($fromPoint->display_number)->toBe('R001')
            // And the two sequences are independent.
            ->and(qnTicket($seed, $owner, ['name' => 'Zara'])->display_number)->toBe('L002');
    });
});

it('refuses a prefix that a screen or a voice could not handle', function (): void {
    // Pure rules, no database: normalisation is what stops `l` and `L` becoming
    // two sequences and two customers holding L001 (§6).
    expect(TicketPrefix::normalise('l'))->toBe('L')
        ->and(TicketPrefix::normalise(' r1 '))->toBe('R1')
        ->and(TicketPrefix::normalise(null))->toBe('A')
        ->and(TicketPrefix::normalise(''))->toBe('A')
        ->and(TicketPrefix::format('A', 7))->toBe('A007')
        // Three digits, then allowed to grow rather than wrap.
        ->and(TicketPrefix::format('A', 1234))->toBe('A1234');

    expect(fn (): string => TicketPrefix::normalise('R 1'))
        ->toThrow(QueueFailed::class, 'no spaces');

    expect(fn (): string => TicketPrefix::normalise('TOOLONG'))
        ->toThrow(QueueFailed::class, 'one to four');

    expect(fn (): string => TicketPrefix::normalise('☺'))
        ->toThrow(QueueFailed::class, 'letters or digits');
});

it('holds the sequence row for update while issuing', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $owner = $this->ownerWithCatalogAccess();

        $statements = [];

        DB::connection('tenant')->listen(function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        qnTicket($seed, $owner);

        $locked = array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'queue_sequences')
                && str_contains($sql, 'for update'),
        );

        // Without this the two obvious implementations — MAX+1 and a counter in
        // PHP — both hand two simultaneous walk-ins the same number (§35).
        expect($locked)->not->toBeEmpty();
    });
});

it('refuses to allocate a number outside a transaction', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();

        // A row lock outside a transaction is released the instant the
        // statement finishes, so it protects nothing while looking exactly like
        // it does. Failing loudly is the only way that is ever noticed.
        expect(fn (): int => app(TicketNumbers::class)
            ->next((int) $seed['branch']->getKey(), '2026-10-01', 'A'))
            ->toThrow(RuntimeException::class, 'inside a transaction');
    });
});

it('refuses a second open ticket for the same stage', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qnTicket($seed, $owner);

        /** @var ServiceJourney $journey */
        $journey = $ticket->journey;
        $stage = $journey->stages()->firstOrFail();

        // The read layer catches the ordinary repeat and hands back the same
        // ticket; the unique index is what guarantees it (§11).
        $again = app(IssueTicket::class)($stage, $owner);

        expect($again->uuid)->toBe($ticket->uuid)
            ->and(QueueTicket::query()->count())->toBe(1);

        // And the database itself refuses a second one, whatever the code does.
        expect(fn (): mixed => DB::connection('tenant')->table('queue_tickets')->insert([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $ticket->branch_id,
            'service_journey_id' => $ticket->service_journey_id,
            'journey_stage_id' => $ticket->journey_stage_id,
            'active_journey_stage_id' => $ticket->journey_stage_id,
            'business_date' => $ticket->business_date->format('Y-m-d'),
            'prefix' => 'A',
            'number' => 999,
            'display_number' => 'A999',
            'state' => 'waiting',
            'issued_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('refuses a second ticket showing the same number on the same day', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $ticket = qnTicket($seed, $this->ownerWithCatalogAccess());

        // The lock is what PREVENTS this; the unique index is what makes it
        // impossible anyway. `active_journey_stage_id` is left null so that the
        // one-open-ticket-per-stage index cannot be what fires: this asserts
        // the NUMBERING backstop specifically (§6).
        expect(fn (): mixed => DB::connection('tenant')->table('queue_tickets')->insert([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $ticket->branch_id,
            'service_journey_id' => $ticket->service_journey_id,
            'journey_stage_id' => $ticket->journey_stage_id,
            'active_journey_stage_id' => null,
            'business_date' => $ticket->business_date->format('Y-m-d'),
            'prefix' => $ticket->prefix,
            'number' => $ticket->number,
            'display_number' => $ticket->display_number,
            'state' => 'waiting',
            'issued_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);

        // And the same string arrived at from a DIFFERENT prefix is refused
        // too, because two people cannot both be holding "A001".
        expect(fn (): mixed => DB::connection('tenant')->table('queue_tickets')->insert([
            'uuid' => (string) Str::uuid(),
            'branch_id' => $ticket->branch_id,
            'service_journey_id' => $ticket->service_journey_id,
            'journey_stage_id' => $ticket->journey_stage_id,
            'active_journey_stage_id' => null,
            'business_date' => $ticket->business_date->format('Y-m-d'),
            'prefix' => 'A0',
            'number' => 1,
            'display_number' => $ticket->display_number,
            'state' => 'waiting',
            'issued_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

it('scopes the service point to its own branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qnSeed();
        $owner = $this->ownerWithCatalogAccess();

        $other = $this->seedBranch('Mansour');
        $elsewhere = $this->seedServicePoint($other, 'X1', 'Other branch desk');

        expect(fn (): QueueTicket => qnTicket($seed, $owner, ['service_point' => $elsewhere->uuid]))
            ->toThrow(QueueFailed::class, 'not available at this branch');

        expect(QueueServicePoint::query()->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
