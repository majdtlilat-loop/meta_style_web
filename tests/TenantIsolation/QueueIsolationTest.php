<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Queue tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/17-QUEUE.md §46.
|
| One database per center. Queue tables carry no `tenant_id` because there is
| nothing to disambiguate — and a queue query with no tenant bound must FAIL
| rather than quietly answer from somewhere.
|
*/

function qiSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qiTicket(array $seed, string $name): QueueTicket
{
    return app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $name,
            idempotencyToken: (string) Str::uuid(),
        ),
        test()->ownerWithCatalogAccess(),
    )['ticket'];
}

it('keeps two centers\' tickets apart even when their ids collide', function (): void {
    // Distinct names and emails: `registerCenter()` is idempotent on its
    // arguments, so the defaults twice would be one center pretending to be two.
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $alphaTicket = $this->asCenter($alpha['tenant'], function (): QueueTicket {
        return qiTicket(qiSeed(), 'Alpha customer');
    });

    $betaTicket = $this->asCenter($beta['tenant'], function (): QueueTicket {
        return qiTicket(qiSeed(), 'Beta customer');
    });

    // The same primary key and the same number, in two databases. That is what
    // one-database-per-center means, and it is why nothing here needs a
    // `tenant_id` to tell them apart.
    expect($alphaTicket->getKey())->toBe($betaTicket->getKey())
        ->and($alphaTicket->display_number)->toBe($betaTicket->display_number)
        ->and($alphaTicket->uuid)->not->toBe($betaTicket->uuid);

    $this->asCenter($alpha['tenant'], function (): void {
        expect(QueueTicket::query()->count())->toBe(1)
            ->and(QueueTicket::query()->firstOrFail()->journey?->customer?->name)->toBe('Alpha customer');
    });

    $this->asCenter($beta['tenant'], function (): void {
        expect(QueueTicket::query()->count())->toBe(1)
            ->and(QueueTicket::query()->firstOrFail()->journey?->customer?->name)->toBe('Beta customer');
    });
});

it('never lets one center\'s numbering touch another\'s', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $seed = qiSeed();

        foreach (['One', 'Two', 'Three'] as $name) {
            qiTicket($seed, $name);
        }
    });

    $betaFirst = $this->asCenter($beta['tenant'], fn (): QueueTicket => qiTicket(qiSeed(), 'Beta first'));

    // Beta starts at A001 regardless of how busy Alpha was.
    expect($betaFirst->display_number)->toBe('A001');

    $this->asCenter($beta['tenant'], function (): void {
        expect(DB::connection('tenant')->table('queue_sequences')->count())->toBe(1)
            ->and((int) DB::connection('tenant')->table('queue_sequences')->value('last_number'))->toBe(1);
    });
});

it('keeps service points, displays and history inside one center', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $seed = qiSeed();
        $this->seedDisplay($seed['branch'], 'Alpha TV');

        $ticket = qiTicket($seed, 'Alpha customer');
        app(CallTicket::class)($ticket, $this->ownerWithCatalogAccess(), $seed['reception']->uuid);
    });

    $this->asCenter($beta['tenant'], function (): void {
        // Beta sees NOTHING of Alpha's, including the destinations and screens
        // that would be the easiest thing to leak onto a wall.
        expect(QueueServicePoint::query()->count())->toBe(0)
            ->and(QueueDisplay::query()->count())->toBe(0)
            ->and(QueueTicket::query()->count())->toBe(0)
            ->and(DB::connection('tenant')->table('queue_ticket_events')->count())->toBe(0)
            ->and(ServiceJourney::query()->count())->toBe(0);
    });
});

it('fails closed when no center is bound', function (): void {
    $this->registerCenter();

    // A queue query outside tenant context must FAIL, never fall back to
    // another center or to the control database (CLAUDE.md).
    expect(fn (): int => QueueTicket::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => QueueServicePoint::query()->count())->toThrow(TenantConnectionNotInitialized::class);
    expect(fn (): int => QueueDisplay::query()->count())->toThrow(TenantConnectionNotInitialized::class);
});

it('leaves no center bound after queue work', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qiSeed();
        $ticket = qiTicket($seed, 'Sara');
        app(CallTicket::class)($ticket, $this->ownerWithCatalogAccess(), $seed['reception']->uuid);
    });

    expect(app(TenantContext::class)->id())->toBeNull();
});

it('puts every queue table in the center database, with no tenant column', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $tables = [
            'queue_service_points',
            'queue_displays',
            'queue_sequences',
            'queue_tickets',
            'queue_ticket_events',
        ];

        foreach ($tables as $table) {
            expect(Schema::connection('tenant')->hasTable($table))->toBeTrue()
                // A `tenant_id` here would be a second way to get isolation
                // wrong, and the first one to be forgotten in a WHERE clause.
                ->and(Schema::connection('tenant')->hasColumn($table, 'tenant_id'))->toBeFalse();
        }

        // And the walk-in columns Phase 8 added to the journey tables.
        expect(Schema::connection('tenant')->hasColumn('service_journeys', 'customer_id'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasColumn('service_journeys', 'tenant_id'))->toBeFalse()
            ->and(Schema::connection('tenant')->hasColumn('journey_stages', 'service_id'))->toBeTrue();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
