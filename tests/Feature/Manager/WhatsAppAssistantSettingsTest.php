<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\SystemRole;
use App\Livewire\Center\Integrations\AssistantSettings;
use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Rayan\Application\Actions\ConfigureAssistant;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Application\RayanSettings;
use App\Modules\Rayan\Domain\Exceptions\RayanFailed;
use App\Modules\Rayan\Domain\Models\AiRun;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\FakeAiProvider;

/*
|--------------------------------------------------------------------------
| The booking bot's settings: RAYAN on/off, an approved model, the tone
|--------------------------------------------------------------------------
|
| docs/27-RAYAN.md §5. The center chooses on/off, an APPROVED model and the
| tone — through ConfigureAssistant, which checks `rayan_ai` and `ai.manage`.
| The change is audited by the channel (RAYAN may not reach the audit trail)
| without the tone's text. And OFF really is off: the switch is honoured by
| the assistant itself, not only shown.
|
*/

it('saves RAYAN settings through its Action, audits no tone, and a switched-off RAYAN never runs', function (): void {
    $center = $this->registerCenter('WA Rayan Center', 'owner@wa-rayan.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        URL::defaults(['center' => $slug]);
        $this->grantAssistant();

        // A platform with one approved model, `fake-model`.
        $fake = new FakeAiProvider;
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $form = Livewire::actingAs($owner)->test(AssistantSettings::class)
            ->assertSet('enabled', true)
            // Only the platform's approved list.
            ->set('model', 'gpt-imaginary')
            ->call('save')
            ->assertHasErrors('model')
            // Tone is length-capped.
            ->set('model', 'fake-model')
            ->set('tone', str_repeat('a', RayanSettings::MAX_INSTRUCTION + 1))
            ->call('save')
            ->assertHasErrors('tone');

        $form->set('tone', 'Warm and brief ZX7')
            ->set('enabled', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('noticeTone', 'success');

        $settings = app(RayanSettings::class);
        $settings->forget();

        expect($settings->enabled())->toBeFalse()
            ->and($settings->customInstruction())->toBe('Warm and brief ZX7')
            ->and($settings->all()['model'] ?? null)->toBe('fake-model');

        // Audited by the channel, with the switches — never the tone's words.
        $audit = TenantAuditLog::query()->where('action', 'rayan.settings.updated')->sole();

        expect($audit->before)->toBe(['enabled' => true, 'model' => null])
            ->and($audit->after)->toBe(['enabled' => false, 'model' => 'fake-model', 'tone_changed' => true])
            ->and(str_contains((string) json_encode($audit->toArray()), 'ZX7'))->toBeFalse('the tone reached the audit trail');

        // The Action refuses an unapproved model whatever the screen sent.
        expect(fn () => app(ConfigureAssistant::class)($owner, true, 'gpt-imaginary', ''))->toThrow(RayanFailed::class);

        // settings.view without ai.manage: reads, cannot change.
        $manager = $this->seedStaffMember(SystemRole::Manager);

        Livewire::actingAs($manager)->test(AssistantSettings::class)
            ->assertSet('enabled', false)
            ->set('enabled', true)
            ->call('save')
            ->assertSet('noticeTone', 'danger');

        $settings->forget();
        expect($settings->enabled())->toBeFalse();

        // OFF means off: a customer message is kept and goes to the team; no
        // run starts and the provider is never asked.
        $account = $this->seedWhatsAppAccount();
        $fake->willSay('This must never be sent.');

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'Can I book for five?')
        ));

        expect(AiRun::query()->count())->toBe(0)
            ->and($fake->received)->toBe([])
            ->and($this->conversationFor()?->status)->toBe(ConversationStatus::HumanRequested);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
