<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Livewire\Center\Conversations;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Message;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager inbox
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §17. Locked without the channel or the assistant and no
| history; masked contact always; take over / close through the Actions; a
| reply needs the channel, and the screen says so rather than failing.
|
*/

it('locks, masks, and moves a thread only through its Actions', function (): void {
    $center = $this->registerCenter('Inbox Center', 'owner@inbox.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $center): void {
        URL::defaults(['center' => $slug]);
        $this->seedBookableCenter();

        // Neither WhatsApp nor RAYAN, and no threads: the upgrade page only.
        Livewire::actingAs($owner)->test(Conversations::class)
            ->assertSee('feature-lock')
            ->assertDontSee('inbox__threads');

        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $thread = $this->seedConversation($account, ConversationStatus::HumanRequested, phone: '+9647501234567');

        Message::query()->create([
            'conversation_id' => $thread->getKey(),
            'direction' => 'inbound',
            'author_type' => 'customer',
            'body' => 'Do you have a slot at five?',
        ]);

        $this->actingAs($owner);

        foreach (['en' => 'Needs a person', 'ar' => 'يحتاج إلى موظف', 'ckb' => 'پێویستی بە کەسێکە'] as $locale => $status) {
            $html = $this->get("http://{$slug}.localhost:8000/manager/conversations?locale={$locale}&thread={$thread->uuid}")
                ->assertOk()
                ->assertSee($status)
                ->assertSee('Do you have a slot at five?')
                ->getContent();

            // The number is masked by the presenter; the full one never renders.
            expect(str_contains($html, '7501234567'))->toBeFalse()
                ->and(preg_match('/\b(conversations|manager_queue)\.[a-z_]+\b/', strip_tags($html)))->toBe(0);
        }

        $inbox = Livewire::actingAs($owner)->test(Conversations::class)
            ->assertViewHas('waiting', 1)
            ->call('open', $thread->uuid)
            ->call('takeOver')
            ->assertSet('noticeTone', 'success');

        expect($thread->fresh()?->status)->toBe(ConversationStatus::HumanActive);

        // An empty reply never reaches the Action.
        $inbox->set('reply', '')->call('send')->assertHasErrors(['reply']);

        // The channel withdrawn: the thread is still readable and closable, and
        // the composer explains why replies stopped instead of failing.
        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'whatsapp_booking'],
            ['mode' => OverrideMode::Revoke, 'reason' => 'downgrade', 'expires_at' => null],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        $inbox->call('$refresh')
            ->assertSee('Do you have a slot at five?')
            ->assertSee(__('conversations.reply_locked'))
            ->call('closeConversation')
            ->assertSet('viewing', '');

        expect($thread->fresh()?->status)->toBe(ConversationStatus::Closed);
    });
});

it('tells somebody without access so, and lists nothing', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $this->seedBookableCenter();
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->seedConversation($account, ConversationStatus::HumanRequested);

        $clerk = $this->staffWith([Permission::CustomerView], 'clerk@inbox.test');

        Livewire::actingAs($clerk)->test(Conversations::class)
            ->assertSee(__('conversations.not_allowed'))
            ->assertViewHas('conversations', []);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
