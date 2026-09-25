<?php

declare(strict_types=1);

use App\Livewire\Center\Integrations\SettingsLink;
use App\Livewire\Center\Integrations\WhatsApp;
use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Application\MessagingProviderRegistry;
use App\Modules\Conversations\Application\WhatsAppReadiness;
use App\Modules\Conversations\Contracts\MessagingProvider;
use App\Modules\Conversations\Domain\Data\InboundBatch;
use App\Modules\Conversations\Domain\Data\InboundEnvelope;
use App\Modules\Conversations\Domain\Data\MessagingCapabilities;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Data\SendResult;
use App\Modules\Conversations\Domain\Data\WhatsAppCredentials;
use App\Modules\Conversations\Domain\Exceptions\WebhookRejected;
use App\Modules\Conversations\Domain\Models\WhatsAppWebhookEvent;
use App\Modules\Conversations\Infrastructure\Providers\MetaWhatsAppCloudProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| "Check setup" — readiness from what was recorded, never a live call
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §§16, 21. The provider contract has no health check and
| the Cloud API adapter is not live-verified, so the check reads facts only:
| credentials present and decryptable, the phone number id's shape, provider
| availability, the webhook handshake or a signed notification, the last
| send's recorded outcome. Each failure names the item; nothing names a secret.
|
*/

/** A messaging provider double registered under Meta's code. */
function waReadinessProvider(bool $available): MessagingProvider
{
    return new class($available) implements MessagingProvider
    {
        public function __construct(private readonly bool $available) {}

        public function code(): string
        {
            return MetaWhatsAppCloudProvider::CODE;
        }

        public function displayName(): string
        {
            return 'Fake WhatsApp';
        }

        public function capabilities(): MessagingCapabilities
        {
            return $this->available ? new MessagingCapabilities(true, true, true, false, false) : MessagingCapabilities::unavailable();
        }

        public function credentialFields(): array
        {
            return ['access_token', 'app_secret', 'verify_token'];
        }

        public function verificationChallenge(WhatsAppCredentials $credentials, InboundEnvelope $envelope): ?string
        {
            return null;
        }

        public function verifySignature(WhatsAppCredentials $credentials, InboundEnvelope $envelope): bool
        {
            return false;
        }

        public function parse(InboundEnvelope $envelope): InboundBatch
        {
            return new InboundBatch([], []);
        }

        public function send(WhatsAppCredentials $credentials, OutboundMessage $message): SendResult
        {
            return SendResult::unknown('fake');
        }
    };
}

it('reports readiness from recorded facts, and names the item that is wrong', function (): void {
    $center = $this->registerCenter('WA Ready Center', 'owner@wa-ready.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        URL::defaults(['center' => $slug]);
        $this->grantWhatsApp();

        $readiness = app(WhatsAppReadiness::class);
        $problem = fn (): ?string => app(WhatsAppReadiness::class)->check(app(WhatsAppReadiness::class)->account())['problem'];

        expect($readiness->check(null)['state'])->toBe('not_connected');

        // Configured, but WhatsApp has not reached the webhook yet.
        $account = $this->seedWhatsAppAccount();
        $result = $readiness->check($account);
        $webhook = collect($result['checks'])->firstWhere('key', 'webhook');

        expect($result['state'])->toBe('waiting')
            ->and($webhook['state'] ?? null)->toBe('pending')
            ->and($readiness->facts($account)['credential_fields'])->toBe(['access_token' => true, 'app_secret' => true, 'verify_token' => true]);

        // The registration handshake: recorded ONCE per account, however often it runs.
        $handshake = new InboundEnvelope('', [], [
            'hub.mode' => 'subscribe',
            'hub.verify_token' => $this->verifyToken(),
            'hub.challenge' => '4242',
        ]);

        expect(app(ReceiveWhatsAppWebhook::class)->challenge($account->uuid, $handshake))->toBe('4242');
        app(ReceiveWhatsAppWebhook::class)->challenge($account->uuid, $handshake);

        expect(WhatsAppWebhookEvent::query()->where('kind', 'handshake')->count())->toBe(1)
            ->and($readiness->check($account)['state'])->toBe('ready')
            ->and($readiness->facts($account)['handshake_at'])->not->toBeNull();

        // Ready: the Settings link raises no alert.
        Livewire::actingAs($owner)->test(SettingsLink::class)
            ->assertSee(__('manager_whatsapp.title'))
            ->assertDontSee(__('manager_whatsapp.link.attention'));

        // Signed with a secret that is not the stored one: rejected and named.
        try {
            app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope($this->metaTextNotification(), 'not-the-app-secret'));
        } catch (WebhookRejected) {
            // Expected: the endpoint answers an empty 403.
        }

        expect($problem())->toBe('signature_failures');

        // The failure alert authorised people see without opening the page.
        Livewire::actingAs($owner)->test(SettingsLink::class)->assertSee(__('manager_whatsapp.link.attention'));

        // The page's "Check setup" says which item, in the reader's language.
        Livewire::actingAs($owner)->test(WhatsApp::class)
            ->call('checkSetup')
            ->assertSet('noticeTone', 'danger')
            ->assertSee(__('manager_whatsapp.problems.signature_failures'));

        // A correctly signed notification after it: the failure is history.
        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope($this->metaTextNotification()));
        expect($problem())->not->toBe('signature_failures');

        // The last send was refused by the provider.
        $conversation = $this->seedConversation($account);
        $conversation->messages()->create([
            'direction' => 'outbound',
            'author_type' => 'staff',
            'body' => 'We open at nine.',
            'delivery_state' => 'failed',
            'failure_code' => 'meta_131047',
        ]);

        $facts = $readiness->facts($account->fresh() ?? $account);
        expect($problem())->toBe('last_send_failed')
            ->and($facts['last_outbound'])->toMatchArray(['state' => 'failed', 'code' => 'meta_131047']);

        // Switched off on purpose.
        $account->forceFill(['enabled' => false])->save();
        expect($problem())->toBe('account_disabled');
        // A deliberate switch-off is a choice, not an alert.
        Livewire::actingAs($owner)->test(SettingsLink::class)->assertDontSee(__('manager_whatsapp.link.attention'));
        $account->forceFill(['enabled' => true])->save();

        // The provider is not available on this installation (a fake provider).
        $real = app(MessagingProviderRegistry::class);
        app()->instance(MessagingProviderRegistry::class, $real->with(waReadinessProvider(false)));
        expect($problem())->toBe('provider_unavailable');
        app()->instance(MessagingProviderRegistry::class, $real->with(waReadinessProvider(true)));

        // Stored credentials that no longer decrypt (an application key change).
        DB::connection('tenant')->table('whatsapp_accounts')->update(['credentials' => 'not-a-ciphertext']);
        $result = app(WhatsAppReadiness::class)->check(app(WhatsAppReadiness::class)->account());

        expect($result['problem'])->toBe('credentials_unreadable')
            ->and(app(WhatsAppReadiness::class)->facts(app(WhatsAppReadiness::class)->account() ?? $account)['credentials_state'])->toBe('unreadable');

        // Nothing the check returns carries a secret.
        $everything = (string) json_encode([$result, app(WhatsAppReadiness::class)->facts($account)]);

        expect(str_contains($everything, $this->appSecret()))->toBeFalse()
            ->and(str_contains($everything, $this->verifyToken()))->toBeFalse();

        app()->instance(MessagingProviderRegistry::class, $real);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
