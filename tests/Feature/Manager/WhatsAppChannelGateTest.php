<?php

declare(strict_types=1);

use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Application\WhatsAppConnections;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppWebhookEvent;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Domain\Models\AiRun;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeAiProvider;

/*
|--------------------------------------------------------------------------
| A channel that is switched off receives nothing
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §19. Losing `whatsapp_booking` stops RECEIVING — even
| for a center that still owns RAYAN — and a manager switching the account off
| does the same. The signature is still verified first, the notification is
| recorded as ignored, and it is answered 200 so Meta stops retrying. Delivery
| statuses for messages already sent keep being applied.
|
*/

it('processes no WhatsApp traffic once whatsapp_booking is gone, even with RAYAN still owned', function (): void {
    $center = $this->registerCenter('WA Gate Center', 'owner@wa-gate.test');
    $slug = $center['registration']->requested_slug;

    $path = $this->asCenter($center['tenant'], function () use ($slug): string {
        $this->grantAssistant();
        $account = $this->seedWhatsAppAccount();

        // Off the center's host (no `center` URL default, e.g. a queue or the
        // API): still the center's own host and slug (ADR-097).
        URL::defaults(['center' => '']);
        expect(app(WhatsAppConnections::class)->webhookUrl($account))
            ->toBe('http://'.$slug.'.localhost:8000/api/v1/whatsapp/'.$slug.'/accounts/'.$account->uuid.'/webhook');

        // On the center's own host, where the Manager shows this address: the slug.
        URL::defaults(['center' => $slug]);

        return (string) parse_url(app(WhatsAppConnections::class)->webhookUrl($account), PHP_URL_PATH);
    });

    $url = 'http://'.$slug.'.localhost:8000'.$path;
    $handshake = fn (string $challenge): string => $url.'?hub.mode=subscribe&hub.verify_token='.$this->verifyToken().'&hub.challenge='.$challenge;

    // While owned, Meta's registration handshake works over real HTTP — the
    // dotted names arrive as `hub_mode` & co. after PHP has parsed them.
    expect($this->get($handshake('5150'))->assertOk()->getContent())->toBe('5150');

    $this->asCenter($center['tenant'], fn () => $this->revokeEntitlement('whatsapp_booking'));

    // A correctly signed customer message: 200, so Meta stops retrying…
    $payload = $this->metaTextNotification(text: 'Book me at five');
    $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars($this->signedHeaders($payload)), (string) json_encode($payload))
        ->assertOk();

    // …and no provider connection can be activated any more.
    $this->get($handshake('5151'))->assertForbidden();

    $this->asCenter($center['tenant'], function (): void {
        // Nothing was processed: no thread, no message, no assistant run.
        expect(Conversation::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(AiRun::query()->count())->toBe(0);

        $event = WhatsAppWebhookEvent::query()->where('kind', 'message')->sole();

        expect($event->signature_verified)->toBeTrue()
            ->and($event->result)->toBe('ignored')
            ->and($event->error_code)->toBe('channel_inactive');
    });
});

it('drops customer messages to a switched-off account but still applies delivery statuses', function (): void {
    $center = $this->registerCenter('WA Off Center', 'owner@wa-off.test');

    $this->asCenter($center['tenant'], function (): void {
        $this->grantAssistant();
        $account = $this->seedWhatsAppAccount(enabled: false);

        $fake = (new FakeAiProvider)->willSay('This must never be sent.');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        // A message sent before the manager switched the account off.
        $conversation = $this->seedConversation($account, phone: '+9647501111111');
        $outbound = $conversation->messages()->create([
            'direction' => 'outbound',
            'author_type' => 'staff',
            'body' => 'See you at five.',
            'provider_message_id' => 'wamid.BEFORE_OFF_1',
            'delivery_state' => 'pending',
        ]);

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(fromPhone: '9647502222222', text: 'Are you open?')
        ));

        expect(Conversation::query()->count())->toBe(1)
            ->and(Message::query()->where('direction', 'inbound')->count())->toBe(0)
            ->and(AiRun::query()->count())->toBe(0)
            ->and($fake->received)->toBe([])
            ->and(WhatsAppWebhookEvent::query()->where('kind', 'message')->value('error_code'))->toBe('account_disabled');

        // The status callback for the earlier message is still applied.
        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaStatusNotification('wamid.BEFORE_OFF_1')
        ));

        expect($outbound->fresh()?->delivery_state?->value)->toBe('sent');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
