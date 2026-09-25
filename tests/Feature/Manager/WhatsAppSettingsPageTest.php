<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Integrations\ConnectionForm;
use App\Livewire\Center\Integrations\WhatsApp;
use App\Modules\Conversations\Application\Actions\ManageWhatsAppAccount;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Rayan\Application\Actions\ConfigureAssistant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager → Settings → WhatsApp
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §21. VIEW (settings.view) sees the status; MANAGE
| (whatsapp.manage) connects, replaces credentials and switches the channel —
| and the Actions refuse whatever the screen shows. Credentials are
| write-only: typed values never come back in a response, a snapshot or a
| page, and the audit trail records THAT they changed, never what they are.
|
*/

/**
 * Submits the connection drawer the way a browser does: the deferred
 * `wire:model` values travel WITH the action, in one request. (Setting them one
 * request at a time would lose them — the component drops typed credentials at
 * the end of every request, which is the point.)
 *
 * @param  array<string, mixed>  $values
 */
function waSubmitConnection(Testable $form, array $values): Testable
{
    return $form->update(calls: [['method' => 'save', 'params' => [], 'path' => '']], updates: $values);
}

it('shows the status to viewers, the controls only to managers, and never a secret', function (): void {
    $center = $this->registerCenter('WA Page Center', 'owner@wa-page.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

        $url = "http://{$slug}.localhost:8000/manager/settings/whatsapp";

        $this->actingAs($owner);
        $html = (string) $this->get($url.'?locale=en')->assertOk()->getContent();

        expect($html)->toContain('Connected')
            ->toContain('Edit connection')
            ->toContain('Check setup')
            ->toContain('106540352242922')
            // The callback URL a manager pastes into Meta — on the center's own
            // host with its slug, the only form ResolvePublicTenant accepts.
            ->toContain("http://{$slug}.localhost:8000/api/v1/whatsapp/{$slug}/accounts/{$account->uuid}/webhook")
            // Content languages, Kurdish always shown as KU.
            ->toContain('KU')
            // The sidebar item, in the Settings group.
            ->toContain('href="'.$url.'"');

        foreach ([$this->appSecret(), $this->verifyToken(), 'test-access-token'] as $secret) {
            expect(str_contains($html, $secret))->toBeFalse('a credential reached the page');
        }

        expect(str_contains($html, '>CKB<'))->toBeFalse();

        foreach (['en', 'ar', 'ckb'] as $locale) {
            $page = (string) $this->get($url.'?locale='.$locale)->assertOk()->getContent();

            expect(preg_match('/\bmanager_whatsapp\.[a-z_.]+/', strip_tags($page)))->toBe(0, 'untranslated key in '.$locale)
                ->and($page)->toContain((string) __('manager_whatsapp.check.title', [], $locale));
        }

        // settings.view alone: the status, read-only.
        $manager = $this->seedStaffMember(SystemRole::Manager);
        $this->actingAs($manager);
        $read = (string) $this->get($url.'?locale=en')->assertOk()->getContent();

        expect($read)->toContain('Connected')
            ->and(str_contains($read, 'Edit connection'))->toBeFalse()
            ->and(str_contains($read, 'wire:click="turnOff"'))->toBeFalse()
            ->and(str_contains($read, '/accounts/'.$account->uuid.'/webhook'))->toBeFalse();

        // Neither settings.view nor whatsapp.manage: no page.
        $employee = $this->seedStaffMember(SystemRole::Employee, name: 'Plain Employee');
        $this->actingAs($employee);
        $this->get($url)->assertForbidden();
    });
});

it('locks the page without whatsapp_booking and refuses every change on the server', function (): void {
    $center = $this->registerCenter('WA Locked Center', 'owner@wa-locked.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        URL::defaults(['center' => $slug]);
        $url = "http://{$slug}.localhost:8000/manager/settings/whatsapp";
        $this->actingAs($owner);

        // Never had it: the upgrade offer, nothing operable.
        $html = (string) $this->get($url.'?locale=en')->assertOk()->getContent();

        expect($html)->toContain('feature-lock')
            ->and(str_contains($html, 'whatsapp-connection-open'))->toBeFalse()
            // Sidebar: shown LOCKED, opening the upgrade prompt.
            ->and($html)->toContain('data-upgrade-feature="whatsapp_booking"');

        // Settings: the link is there, marked as not in the plan.
        $settings = (string) $this->get("http://{$slug}.localhost:8000/manager/settings?locale=en")->assertOk()->getContent();
        expect($settings)->toContain($url)->toContain('Not in plan');

        // The server refuses, whatever a client sends.
        $credentials = ['access_token' => 'tok-1', 'app_secret' => 'sec-1', 'verify_token' => 'ver-1'];

        expect(fn () => app(ManageWhatsAppAccount::class)->save($owner, 'Main', '106540352242922', null, null, $credentials, true))
            ->toThrow(EntitlementRequired::class);

        $refused = Livewire::actingAs($owner)->test(ConnectionForm::class)->call('show')->assertSet('open', false);

        waSubmitConnection($refused, [
            'displayName' => 'Main',
            'phoneNumberId' => '106540352242922',
            'credentials.access_token' => 'tok-1',
            'credentials.app_secret' => 'sec-1',
            'credentials.verify_token' => 'ver-1',
        ])->assertHasErrors('form');

        expect(WhatsAppAccount::query()->count())->toBe(0)
            ->and(fn () => app(ConfigureAssistant::class)($owner, false, null, ''))->toThrow(EntitlementRequired::class);

        // An account from before the downgrade: its status stays readable,
        // nothing is operable, and the Action still refuses.
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->revokeEntitlement('whatsapp_booking');

        $locked = (string) $this->get($url.'?locale=en')->assertOk()->getContent();

        expect($locked)->toContain('Main line')
            ->toContain('feature-lock')
            ->and(str_contains($locked, 'wire:click="turnOff"'))->toBeFalse()
            ->and(str_contains($locked, 'Check setup'))->toBeFalse()
            ->and(str_contains($locked, '/accounts/'.$account->uuid.'/webhook'))->toBeFalse();

        expect(fn () => app(ManageWhatsAppAccount::class)->setEnabled($owner, $account, false))
            ->toThrow(EntitlementRequired::class);

        Livewire::actingAs($owner)->test(WhatsApp::class)
            ->call('turnOff')
            ->assertSet('noticeTone', 'danger');

        expect($account->fresh()?->enabled)->toBeTrue();
    });
});

it('connects through the one credential writer, keeps typed secrets out of every response, and audits no value', function (): void {
    $center = $this->registerCenter('WA Connect Center', 'owner@wa-connect.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        URL::defaults(['center' => $slug]);
        $this->grantWhatsApp();

        $secrets = [
            'access_token' => 'EAAG-SECRET-ACCESS-7Q2',
            'app_secret' => 'app-secret-9ZK',
            'verify_token' => 'verify-phrase-4MX',
        ];

        // A partial set is refused — all or nothing — and what was typed does
        // not come back in the response.
        $typed = [];

        foreach ($secrets as $field => $value) {
            $typed['credentials.'.$field] = $value;
        }

        $details = ['displayName' => 'Front desk', 'phoneNumberId' => '106540352242922', 'displayPhoneNumber' => '+964 750 123 4567'];

        $form = Livewire::actingAs($owner)->test(ConnectionForm::class)->call('show')->assertSet('open', true);

        waSubmitConnection($form, $details + ['credentials.access_token' => $secrets['access_token']])
            ->assertHasErrors(['credentials.app_secret', 'credentials.verify_token']);

        expect(str_contains((string) json_encode($form->snapshot), $secrets['access_token']))->toBeFalse('a typed secret survived in the snapshot')
            ->and(str_contains($form->html(), $secrets['access_token']))->toBeFalse('a typed secret was rendered')
            ->and(WhatsAppAccount::query()->count())->toBe(0);

        // A path in the phone number id never reaches the Action (ADR-071).
        waSubmitConnection($form, ['phoneNumberId' => '../evil'] + $details + $typed)->assertHasErrors('phoneNumberId');
        expect(WhatsAppAccount::query()->count())->toBe(0);

        // The full set: saved encrypted, and cleared from the component.
        waSubmitConnection($form, $details + $typed)
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertSet('credentials', [])
            ->assertDispatched('whatsapp-connection-saved');

        foreach ($secrets as $value) {
            expect(str_contains((string) json_encode($form->snapshot), $value))->toBeFalse('a saved secret stayed in the snapshot');
        }

        $account = WhatsAppAccount::query()->sole();
        $raw = (string) DB::connection('tenant')->table('whatsapp_accounts')->value('credentials');

        expect($account->isConfigured())->toBeTrue()
            ->and($account->enabled)->toBeTrue()
            ->and($account->display_phone_number)->toBe('+964 750 123 4567')
            ->and($account->readableCredentials())->toBe($secrets)
            ->and(str_contains($raw, $secrets['app_secret']))->toBeFalse('credentials are not encrypted at rest');

        // Audited at Warning, with no value and no digest of one.
        $audit = TenantAuditLog::query()->where('action', 'whatsapp.account.configured')->sole();
        $serialised = (string) json_encode($audit->toArray());

        foreach ($secrets as $value) {
            expect(str_contains($serialised, $value))->toBeFalse('a credential reached the audit trail');
        }

        // Warning severity is what marks a credential replacement (§20).
        expect($audit->getAttribute('severity'))->toBe('warning')
            ->and($audit->after['enabled'] ?? null)->toBeTrue()
            ->and($audit->after['phone_number_id'] ?? null)->toBe('106540352242922');

        // Renaming with the credential fields left empty keeps what is stored.
        Livewire::actingAs($owner)->test(ConnectionForm::class)
            ->call('show')
            ->assertSet('displayName', 'Front desk')
            ->assertSet('credentials', [])
            ->set('displayName', 'Reception')
            ->call('save')
            ->assertHasNoErrors();

        expect($account->fresh()?->display_name)->toBe('Reception')
            ->and($account->fresh()?->readableCredentials())->toBe($secrets);

        // The page shows each credential as configured, never its value.
        $this->actingAs($owner);
        $html = (string) $this->get("http://{$slug}.localhost:8000/manager/settings/whatsapp?locale=en")->assertOk()->getContent();

        expect($html)->toContain('Configured');

        foreach ($secrets as $value) {
            expect(str_contains($html, $value))->toBeFalse('a credential reached the page');
        }

        // Off and on again, each audited.
        $page = Livewire::actingAs($owner)->test(WhatsApp::class)->call('turnOff')->assertSet('noticeTone', 'success');
        expect($account->fresh()?->enabled)->toBeFalse();

        $page->call('turnOn')->assertSet('noticeTone', 'success');
        expect($account->fresh()?->enabled)->toBeTrue()
            ->and(TenantAuditLog::query()->where('action', 'whatsapp.account.disabled')->count())->toBe(1)
            ->and(TenantAuditLog::query()->where('action', 'whatsapp.account.enabled')->count())->toBe(1);

        // settings.view is not whatsapp.manage: the screen and the Action both refuse.
        $manager = $this->seedStaffMember(SystemRole::Manager);

        Livewire::actingAs($manager)->test(ConnectionForm::class)->call('show')->assertSet('open', false);
        Livewire::actingAs($manager)->test(WhatsApp::class)->call('turnOff')->assertSet('noticeTone', 'danger');

        // Nor can a viewer open the drawer by setting it, or save without it.
        expect(fn () => Livewire::actingAs($manager)->test(ConnectionForm::class)->set('open', true))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        waSubmitConnection(Livewire::actingAs($manager)->test(ConnectionForm::class), [
            'displayName' => 'Hijacked',
            'phoneNumberId' => '106540352242922',
        ] + $typed)->assertForbidden();

        expect($account->fresh()?->display_name)->toBe('Reception')
            ->and($account->fresh()?->readableCredentials())->toBe($secrets)
            ->and($account->fresh()?->enabled)->toBeTrue()
            ->and(fn () => app(ManageWhatsAppAccount::class)->setEnabled($manager, $account, false))->toThrow(AuthorizationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
