<?php

declare(strict_types=1);

use App\Livewire\Center\QueueBoard;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\LivewireWireAliases;

/*
|--------------------------------------------------------------------------
| What the Call button on a ticket actually runs
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §14. A ticket's buttons are `wire:click` expressions the
| BROWSER evaluates against Livewire's `$wire` proxy, where `call` is
| Livewire's own `$call(method, …params)`: `call('<uuid>')` made the uuid the
| method name and every Call press failed (tests/Architecture/
| LivewireActionNamesTest.php). A page that renders proves nothing about that,
| so the rendered binding is read back and invoked exactly as written.
|
*/

function qabWaitingTicket(string $name): QueueTicket
{
    $seed = test()->seedBookableCenter();
    test()->grantQueueEntitlements();

    return app(CreateWalkInTicket::class)(
        new WalkInRequest(branchUuid: $seed['branch']->uuid, serviceUuids: [$seed['service']->uuid], name: $name),
        test()->ownerWithCatalogAccess(),
        [],
    )['ticket'];
}

/**
 * The `method('argument')` of the one button whose label is $label.
 *
 * @return array{0: string, 1: string}|null
 */
function qabButton(string $html, string $label): ?array
{
    preg_match_all('/<button\b[^>]*\bwire:click="([^"]*)"[^>]*>(.*?)<\/button>/s', $html, $buttons, PREG_SET_ORDER);

    $found = [];
    foreach ($buttons as [, $expression, $inner]) {
        if (trim(strip_tags($inner)) === $label && preg_match('/^\s*([A-Za-z_$][A-Za-z0-9_]*)\(\'([^\']*)\'\)\s*$/', html_entity_decode($expression), $call) === 1) {
            $found[] = [$call[1], $call[2]];
        }
    }

    return count($found) === 1 ? $found[0] : null;
}

it('binds Call and Recall to a board action that receives the ticket uuid', function (): void {
    $center = $this->registerCenter('Queue Binding Center', 'owner@queue-binding.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        URL::defaults(['center' => $center['registration']->requested_slug]);
        app()->setLocale('en');
        $ticket = qabWaitingTicket('Binding Guest');
        $owner = $this->ownerWithCatalogAccess();

        $board = Livewire::actingAs($owner)->test(QueueBoard::class);
        $call = qabButton($board->html(), __('manager_queue.actions.call'));

        // One Call button, written as method('<ticket uuid>')…
        expect($call)->not->toBeNull()
            ->and($call[1] ?? null)->toBe($ticket->uuid)
            // …whose method the browser hands to the component, not to $wire…
            ->and(in_array($call[0] ?? '', LivewireWireAliases::names(), true))->toBeFalse()
            ->and(method_exists(QueueBoard::class, $call[0] ?? ''))->toBeTrue()
            ->and((new ReflectionMethod(QueueBoard::class, (string) ($call[0] ?? '')))->isPublic())->toBeTrue();

        // …and which, invoked exactly as rendered, calls that ticket once.
        $board->call($call[0], $call[1])->assertSet('noticeTone', 'success');

        expect($ticket->fresh()?->state)->toBe(TicketState::Called)
            ->and($ticket->fresh()?->call_count)->toBe(1);

        // Recall is the same action on the same uuid, and counts a second call.
        $recall = qabButton($board->html(), __('manager_queue.actions.recall'));
        expect($recall)->toBe([$call[0], $ticket->uuid]);

        $board->call($recall[0], $recall[1])->assertSet('noticeTone', 'success');
        expect($ticket->fresh()?->call_count)->toBe(2);
    });
});

it('answers a malformed or unknown ticket uuid with a notice and changes nothing', function (): void {
    $center = $this->registerCenter('Queue Binding Unknown', 'owner@queue-binding-unknown.test');

    $this->asCenter($center['tenant'], function () use ($center): void {
        URL::defaults(['center' => $center['registration']->requested_slug]);
        app()->setLocale('en');
        $ticket = qabWaitingTicket('Unknown Guest');
        $owner = $this->ownerWithCatalogAccess();

        $board = Livewire::actingAs($owner)->test(QueueBoard::class);

        foreach (['not-a-uuid', "x') OR ('1'='1", (string) Str::uuid()] as $forged) {
            $board->call('callTicket', $forged)
                ->assertSet('noticeTone', 'danger')
                ->assertSet('notice', __('manager_queue.errors.not_found'));
        }

        expect($ticket->fresh()?->state)->toBe(TicketState::Waiting)
            ->and($ticket->fresh()?->call_count)->toBe(0);
    });
});

it('never calls another center\'s ticket, whatever uuid is posted', function (): void {
    $alpha = $this->registerCenter('Queue Binding Alpha', 'owner@queue-binding-alpha.test');
    $beta = $this->registerCenter('Queue Binding Beta', 'owner@queue-binding-beta.test');

    $foreign = $this->asCenter($beta['tenant'], fn (): string => qabWaitingTicket('Beta Guest')->uuid);

    $this->asCenter($alpha['tenant'], function () use ($alpha, $foreign): void {
        URL::defaults(['center' => $alpha['registration']->requested_slug]);
        app()->setLocale('en');
        qabWaitingTicket('Alpha Guest');

        Livewire::actingAs($this->ownerWithCatalogAccess())->test(QueueBoard::class)
            ->call('callTicket', $foreign)
            ->assertSet('noticeTone', 'danger')
            ->assertSet('notice', __('manager_queue.errors.not_found'));
    });

    // Untouched in its own center: still waiting, never called.
    $this->asCenter($beta['tenant'], function () use ($foreign): void {
        $ticket = QueueTicket::query()->where('uuid', $foreign)->sole();

        expect($ticket->state)->toBe(TicketState::Waiting)
            ->and($ticket->call_count)->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
