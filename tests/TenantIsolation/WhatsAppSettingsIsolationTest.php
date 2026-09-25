<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Livewire\Center\Integrations\ConnectionForm;
use App\Livewire\Center\Integrations\WhatsApp;
use App\Modules\Conversations\Application\WhatsAppConnections;
use App\Modules\Conversations\Application\WhatsAppReadiness;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager → WhatsApp — tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/25-WHATSAPP.md §§4, 21. A center's WhatsApp
| account lives in its own database. Another center's settings page never
| shows it, its connection form never edits it, and its webhook address never
| reaches it — the account uuid alone, on the wrong center's key, is refused.
|
| This is a RELEASE GATE. Never skipped.
|
*/

it('never shows, edits or routes to another center\'s WhatsApp account', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');
    $betaOwner = $this->ownerOf($beta['tenant']);
    $betaSlug = $beta['registration']->requested_slug;

    $alphaAccount = $this->asCenter($alpha['tenant'], function (): WhatsAppAccount {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $account->forceFill(['display_name' => 'Alpha front line'])->save();

        return $account;
    });

    $betaPath = $this->asCenter($beta['tenant'], function () use ($betaOwner, $betaSlug, $alphaAccount): string {
        URL::defaults(['center' => $betaSlug]);
        $this->grantWhatsApp();

        // Beta has no account: nothing of Alpha's resolves here.
        expect(app(WhatsAppReadiness::class)->account())->toBeNull();

        $this->actingAs($betaOwner);
        $html = (string) $this->get("http://{$betaSlug}.localhost:8000/manager/settings/whatsapp?locale=en")->assertOk()->getContent();

        expect($html)->toContain('WhatsApp is not connected')
            ->and(str_contains($html, 'Alpha front line'))->toBeFalse('another center\'s account name was shown')
            ->and(str_contains($html, $alphaAccount->uuid))->toBeFalse('another center\'s account uuid was shown');

        // Beta's form starts empty and creates Beta's OWN account.
        $form = Livewire::actingAs($betaOwner)->test(ConnectionForm::class)
            ->call('show')
            ->assertSet('displayName', '')
            ->assertSet('phoneNumberId', '');

        // Submitted as a browser does: the typed values travel with the action.
        $form->update(calls: [['method' => 'save', 'params' => [], 'path' => '']], updates: [
            'displayName' => 'Beta line',
            'phoneNumberId' => '209876543210987',
            'credentials.access_token' => 'beta-token',
            'credentials.app_secret' => 'beta-secret',
            'credentials.verify_token' => 'beta-verify',
        ])->assertHasNoErrors();

        $own = WhatsAppAccount::query()->sole();

        expect($own->uuid)->not->toBe($alphaAccount->uuid)
            ->and($own->display_name)->toBe('Beta line');

        // Switching the channel off touches Beta's account only.
        Livewire::actingAs($betaOwner)->test(WhatsApp::class)->call('turnOff')->assertSet('noticeTone', 'success');

        expect($own->fresh()?->enabled)->toBeFalse();

        return (string) parse_url(app(WhatsAppConnections::class)->webhookUrl($own), PHP_URL_PATH);
    });

    $this->asCenter($alpha['tenant'], function (): void {
        $untouched = WhatsAppAccount::query()->sole();

        expect($untouched->display_name)->toBe('Alpha front line')
            ->and($untouched->enabled)->toBeTrue()
            ->and($untouched->readableCredentials()['app_secret'] ?? null)->toBe($this->appSecret())
            // Beta's changes were audited in Beta's database, not here.
            ->and(TenantAuditLog::query()->where('action', 'like', 'whatsapp.account.%')->count())->toBe(0);
    });

    // Alpha's account uuid on Beta's public key: not found, so refused.
    $crossed = str_replace((string) basename(dirname($betaPath)), $alphaAccount->uuid, $betaPath);

    expect($crossed)->not->toBe($betaPath);

    $this->get('http://'.$betaSlug.'.localhost:8000'.$crossed.'?hub.mode=subscribe&hub.verify_token='.$this->verifyToken().'&hub.challenge=9')
        ->assertForbidden();
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
