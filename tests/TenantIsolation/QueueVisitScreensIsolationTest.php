<?php

declare(strict_types=1);

use App\Livewire\Center\Conversations;
use App\Livewire\Center\Journey\VisitPanel;
use App\Livewire\Center\Queue\TicketPanel;
use App\Livewire\Center\QueueBoard;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\QueueBoardQuery;
use App\Modules\Queue\Application\QueueSetupQuery;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\JourneyBoardQuery;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager queue and visit screens — tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md. The Manager screens resolve every uuid they are sent
| through the bound center's scoped finders. A uuid from another center is
| simply not there: nothing to read, nothing to act on.
|
*/

function qvsTicket(string $name): array
{
    $seed = test()->seedBookableCenter();
    test()->grantQueueEntitlements();
    test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');
    test()->seedDisplay($seed['branch'], $name.' TV');

    $result = app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $name,
            idempotencyToken: (string) Str::uuid(),
        ),
        test()->ownerWithCatalogAccess(),
    );

    // And one inbox thread, so the Manager inbox has something to leak.
    test()->grantWhatsApp();
    $thread = test()->seedConversation(test()->seedWhatsAppAccount(), ConversationStatus::HumanRequested);
    Message::query()->create([
        'conversation_id' => $thread->getKey(),
        'direction' => 'inbound',
        'author_type' => 'customer',
        'body' => $name.' asked about a slot at five',
    ]);

    return ['ticket' => $result['ticket']->uuid, 'journey' => $result['journey']->uuid, 'thread' => $thread->uuid];
}

it('never opens, lists or acts on another center\'s tickets, visits, screens or threads', function (): void {
    $alpha = $this->registerCenter('Queue Alpha', 'owner@queue-alpha.test');
    $beta = $this->registerCenter('Queue Beta', 'owner@queue-beta.test');

    $alphaRefs = $this->asCenter($alpha['tenant'], fn (): array => qvsTicket('Alpha Guest'));
    $betaRefs = $this->asCenter($beta['tenant'], fn (): array => qvsTicket('Beta Guest'));

    $this->asCenter($alpha['tenant'], function () use ($alpha, $betaRefs, $alphaRefs): void {
        URL::defaults(['center' => $alpha['registration']->requested_slug]);
        $owner = $this->ownerWithCatalogAccess();

        // The scoped finders answer "not here", not "forbidden".
        expect(fn () => app(QueueBoardQuery::class)->find($owner, $betaRefs['ticket']))
            ->toThrow(ModelNotFoundException::class)
            ->and(fn () => app(JourneyBoardQuery::class)->find($owner, $betaRefs['journey']))
            ->toThrow(ModelNotFoundException::class);

        // Setup lists only this center's screens.
        $screens = array_column(app(QueueSetupQuery::class)->displays($owner), 'name');
        expect($screens)->toBe(['Alpha Guest TV']);

        // The board lists this center's customer only.
        Livewire::actingAs($owner)->test(QueueBoard::class)
            ->assertSee('Alpha Guest')
            ->assertDontSee('Beta Guest')
            // A crafted call on the other center's uuid is "not found".
            ->call('callTicket', $betaRefs['ticket'])
            ->assertSet('notice', __('manager_queue.errors.not_found'));

        // The drawers render nothing for a foreign uuid.
        Livewire::actingAs($owner)->test(TicketPanel::class)
            ->call('open', $betaRefs['ticket'])
            ->assertSet('ticket', '')
            ->assertDontSee('Beta Guest');

        Livewire::actingAs($owner)->test(VisitPanel::class)
            ->call('open', $betaRefs['journey'])
            ->assertSet('journey', '')
            ->assertDontSee('Beta Guest');

        // The inbox: a foreign thread in the URL opens nothing, and a crafted
        // take-over on it is "not found" — never another center's customer.
        Livewire::actingAs($owner)->test(Conversations::class)
            ->set('viewing', $alphaRefs['thread'])
            ->assertSee('Alpha Guest asked about a slot at five')
            ->set('viewing', $betaRefs['thread'])
            ->assertDontSee('Beta Guest asked about a slot at five')
            ->call('takeOver')
            ->assertSet('notice', __('manager_queue.errors.not_found'));

        expect(QueueTicket::query()->where('uuid', $alphaRefs['ticket'])->firstOrFail()->state)->toBe(TicketState::Waiting);
    });

    // And the other center's ticket was never touched.
    $this->asCenter($beta['tenant'], function () use ($betaRefs): void {
        expect(QueueTicket::query()->where('uuid', $betaRefs['ticket'])->firstOrFail()->state)->toBe(TicketState::Waiting)
            ->and(QueueTicket::query()->where('uuid', $betaRefs['ticket'])->firstOrFail()->call_count)->toBe(0)
            ->and(Conversation::query()->where('uuid', $betaRefs['thread'])->firstOrFail()->status)->toBe(ConversationStatus::HumanRequested);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
