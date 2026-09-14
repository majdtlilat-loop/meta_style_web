<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\HoldTicket;
use App\Modules\Queue\Application\Actions\ResumeTicket;
use App\Modules\Queue\Application\Actions\TransferTicket;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\Models\QueueTicketEvent;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Queue history
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §13, §37.
|
| APPEND ONLY. "Called three times, held once, transferred to the laser desk,
| then served" is a question the product has to answer, and a `called_at` column
| can hold one of those calls.
|
| This is DOMAIN data, not the audit log. Audit answers "who changed what, and
| were they allowed to"; this answers "what happened to this customer". A report
| must never be built on a security record (docs/08-AUDIT-SECURITY.md).
|
*/

function qhSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');
    $seed['laser'] = test()->seedServicePoint($seed['branch'], 'L2', 'Laser Room 2', sortOrder: 1);

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qhTicket(array $seed, User $owner, string $name = 'Sara'): QueueTicket
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

it('records the first call, with where the customer was sent and who sent them', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qhSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qhTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        $events = $ticket->events()->get();

        expect($events)->toHaveCount(2)
            ->and($events[0]->type->value)->toBe('issued')
            ->and($events[1]->type->value)->toBe('called')
            ->and($events[1]->sequence)->toBe(2)
            ->and($events[1]->from_state)->toBe('waiting')
            ->and($events[1]->to_state)->toBe('called')
            // The destination is recorded per CALL, not only as the ticket's
            // current value (§16).
            ->and($events[1]->service_point_id)->toBe($seed['reception']->id)
            ->and($events[1]->actor_label)->toBe($owner->name)
            ->and($events[1]->actor_type)->toBe('staff')
            // The public announcement identifier, which is what makes a screen
            // speak exactly once per call (correction 2).
            ->and($events[1]->uuid)->not->toBeNull();
    });
});

it('appends a recall rather than overwriting the call before it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qhSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qhTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);
        app(CallTicket::class)($ticket->fresh(), $owner, $seed['reception']->uuid);
        app(CallTicket::class)($ticket->fresh(), $owner, $seed['reception']->uuid);

        $types = $ticket->events()->pluck('type')->map(fn ($t): string => $t->value)->all();

        expect($types)->toBe(['issued', 'called', 'recalled', 'recalled'])
            ->and($ticket->fresh()?->call_count)->toBe(3)
            // Sequences are contiguous and allocated under the ticket lock.
            ->and($ticket->events()->pluck('sequence')->all())->toBe([1, 2, 3, 4]);

        // Every recall has its own announcement identifier, which is why a
        // television speaks again rather than staying silent on an unchanged
        // number.
        $announcements = $ticket->events()
            ->whereIn('type', ['called', 'recalled'])
            ->pluck('uuid')
            ->all();

        expect(array_unique($announcements))->toHaveCount(3);
    });
});

it('keeps the earlier calls when a customer is transferred', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qhSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qhTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);
        app(TransferTicket::class)($ticket->fresh(), $owner, $seed['laser']->uuid, null, 'Room freed up');
        app(CallTicket::class)($ticket->fresh(), $owner, $seed['laser']->uuid);

        $events = $ticket->events()->get();

        expect($events->pluck('type')->map(fn ($t): string => $t->value)->all())
            ->toBe(['issued', 'called', 'transferred', 'called'])
            /*
             * The FIRST call still says Reception. A `service_point_id` column
             * on its own would have said only where they ended up, and "we sent
             * them to the wrong desk first" is exactly the thing somebody
             * reviewing a busy Saturday needs to see (§16).
             */
            ->and($events[1]->service_point_id)->toBe($seed['reception']->id)
            ->and($events[2]->service_point_id)->toBe($seed['laser']->id)
            ->and($events[2]->reason)->toBe('Room freed up')
            ->and($events[3]->service_point_id)->toBe($seed['laser']->id);
    });
});

it('records holds, skips and resumes as separate facts', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qhSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qhTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner);
        app(HoldTicket::class)($ticket->fresh(), $owner, true);
        app(ResumeTicket::class)($ticket->fresh(), $owner);
        app(HoldTicket::class)($ticket->fresh(), $owner, false, 'Stepped outside');

        $events = $ticket->events()->get();

        expect($events->pluck('type')->map(fn ($t): string => $t->value)->all())
            // `skipped` and `held` are different rows because they mean
            // different things when somebody reads the day back: one is "we
            // called and nobody came", the other is "they asked us to wait"
            // (§15).
            ->toBe(['issued', 'called', 'skipped', 'resumed', 'held'])
            ->and($events[2]->reason)->toBe('No response')
            ->and($events[4]->reason)->toBe('Stepped outside');

        // And everything §38 wants to be able to calculate later is derivable
        // from these rows without a single precomputed aggregate.
        expect(QueueTicketEvent::query()->where('type', 'skipped')->count())->toBe(1)
            ->and($ticket->fresh()?->skip_count)->toBe(1);
    });
});

it('never reuses a history sequence for one ticket', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qhSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qhTicket($seed, $owner);

        for ($i = 0; $i < 4; $i++) {
            app(CallTicket::class)($ticket->fresh(), $owner);
        }

        $sequences = $ticket->events()->pluck('sequence')->all();

        expect($sequences)->toBe([1, 2, 3, 4, 5])
            ->and(count(array_unique($sequences)))->toBe(count($sequences));
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
