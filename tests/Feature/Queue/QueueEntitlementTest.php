<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\IssueTicket;
use App\Modules\Queue\Application\TicketPrinter;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Buying the queue
|--------------------------------------------------------------------------
|
| docs/05-ENTITLEMENTS.md, docs/17-QUEUE.md §19.
|
| NO SEEDED PLAN SELLS THE QUEUE. Phase 8 built a capability; which commercial
| package receives it is a separate decision, made deliberately and later, and
| until then a center that wants the queue buys it as a per-tenant add-on.
|
| Which makes this file the one that keeps every other queue test honest. They
| all call `grantQueueEntitlements()`; these prove that call is doing real work
| rather than decorating a plan that already granted it.
|
| Three keys, three different answers, because they are three different
| products: run a queue at the desk, put it on a screen, have the screen speak.
|
*/

function qeSeed(): array
{
    $seed = test()->seedBookableCenter();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qeRequest(array $seed): WalkInRequest
{
    return new WalkInRequest(
        branchUuid: $seed['branch']->uuid,
        serviceUuids: [$seed['service']->uuid],
        name: 'Sara Ahmed',
        idempotencyToken: (string) Str::uuid(),
    );
}

it('sells the queue to nobody by default', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qeSeed();
        $owner = $this->ownerWithCatalogAccess();

        // A fully permissioned OWNER of a center on the standard trial. The
        // refusal is commercial, not authorization — which is the distinction
        // this whole layer exists to keep (docs/05 §1).
        expect(fn (): array => app(CreateWalkInTicket::class)(qeRequest($seed), $owner))
            ->toThrow(EntitlementRequired::class);

        expect(QueueTicket::query()->count())->toBe(0);
    });
});

it('still takes walk-in VISITS with no queue entitlement at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qeSeed();
        $owner = $this->ownerWithCatalogAccess();

        // The point of the separation: a center that never buys the queue
        // still serves people who walk in. It simply hands them no number.
        $journey = app(CreateWalkInVisit::class)(qeRequest($seed), $owner);

        expect($journey)->toBeInstanceOf(ServiceJourney::class)
            ->and($journey->isWalkIn())->toBeTrue()
            ->and($journey->stages()->count())->toBe(1)
            ->and(QueueTicket::query()->count())->toBe(0);

        // And issuing a ticket for that visit's stage is what refuses.
        $stage = $journey->stages()->firstOrFail();

        expect(fn (): QueueTicket => app(IssueTicket::class)($stage, $owner))
            ->toThrow(EntitlementRequired::class);
    });
});

it('runs the desk queue once the center buys queue_management', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qeSeed();
        $owner = $this->ownerWithCatalogAccess();

        $this->grantQueueEntitlements(['queue_management']);

        $ticket = app(CreateWalkInTicket::class)(qeRequest($seed), $owner)['ticket'];

        expect($ticket->display_number)->toBe('A001')
            // Printing is part of running a desk queue, not a separate purchase.
            ->and(app(TicketPrinter::class)->payload($ticket, $owner))
            ->toHaveKey('number', 'A001');
    });
});

it('serves no screen until the center buys queue_display', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qeSeed();

        // Management only. A center may run a queue at the desk without paying
        // for the screens in the waiting room.
        $this->grantQueueEntitlements(['queue_management']);

        return $this->seedDisplay($seed['branch']);
    });

    $url = '/api/v1/queue/'.$center['tenant']->publicKey.'/displays/'.$display->public_key;

    $this->getJson($url)->assertStatus(403);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantQueueEntitlements(['queue_display']);
    });

    $this->getJson($url)->assertStatus(200);
});

it('speaks nothing until the center buys queue_voice', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qeSeed();
        $owner = $this->ownerWithCatalogAccess();

        // Everything except the voice.
        $this->grantQueueEntitlements(['queue_management', 'queue_display']);

        $screen = $this->seedDisplay($seed['branch']);

        $result = app(CreateWalkInTicket::class)(qeRequest($seed), $owner);

        app(CallTicket::class)(
            $result['ticket'],
            $owner,
            $seed['reception']->uuid,
        );

        return $screen;
    });

    $url = '/api/v1/queue/'.$center['tenant']->publicKey.'/displays/'.$display->public_key;

    // The screen still shows the call — it just does not say it. The visual
    // product and the spoken one are sold separately, and the payload says so
    // rather than the browser deciding.
    $silent = $this->getJson($url)->assertStatus(200)->json('data');

    expect($silent['now_calling']['number'])->toBe('A001')
        ->and($silent['display']['voice_enabled'])->toBeFalse()
        ->and($silent['announcement'])->toBeNull();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantQueueEntitlements(['queue_voice']);
    });

    $spoken = $this->getJson($url)->assertStatus(200)->json('data');

    expect($spoken['display']['voice_enabled'])->toBeTrue()
        ->and($spoken['announcement'])->not->toBeNull()
        ->and($spoken['announcement']['lines']['en'])->toContain('A001');
});

it('withdraws the queue the moment the add-on is revoked', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qeSeed();
        $owner = $this->ownerWithCatalogAccess();

        $this->grantQueueEntitlements();

        app(CreateWalkInTicket::class)(qeRequest($seed), $owner);

        expect(QueueTicket::query()->count())->toBe(1);

        // A revocation beats a grant, so an add-on that lapses stops working
        // at once rather than at the end of some cache window.
        $tenantId = (string) app(TenantContext::class)->id();

        // The add-on ROW is re-decided rather than a second one added: the
        // table carries one override per (tenant, entitlement), so a lapsed
        // add-on is a mode change, not a pile of contradicting rows.
        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'entitlement' => 'queue_management'],
            ['mode' => OverrideMode::Revoke],
        );

        app(Entitlements::class)->invalidate($tenantId);

        expect(fn (): array => app(CreateWalkInTicket::class)(qeRequest($seed), $owner))
            ->toThrow(EntitlementRequired::class)
            ->and(QueueTicket::query()->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
