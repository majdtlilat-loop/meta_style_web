<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Integrations\BookingConfirmations;
use App\Modules\Conversations\Application\Actions\ConfigureWhatsAppNotifications;
use App\Modules\Conversations\Application\GuestConfirmationReadiness;
use App\Modules\Conversations\Application\WhatsAppNotificationSettings;
use App\Modules\Conversations\Domain\Enums\ConversationChannel;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Enums\DeliveryState;
use App\Modules\Conversations\Domain\Enums\MessageAuthor;
use App\Modules\Conversations\Domain\Enums\MessageDirection;
use App\Modules\Conversations\Domain\Enums\NoticePurpose;
use App\Modules\Conversations\Domain\Enums\NoticeStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppOutboundNotice;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Settings → WhatsApp → guest booking confirmations
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §22. The switch is the center's choice and goes through
| ConfigureWhatsAppNotifications (`whatsapp_booking` + `whatsapp.manage`,
| audited). The status beside it is what the sender would actually do: "Not
| sending" and why — never a switch that pretends.
|
*/

it('shows the real sending state, lets managers switch it, and audits the change', function (): void {
    $center = $this->registerCenter('WA Confirm Center', 'owner@wa-confirm.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        URL::defaults(['center' => $slug]);
        $url = "http://{$slug}.localhost:8000/manager/settings/whatsapp?locale=en";

        // Without the channel, the Action refuses whatever a screen sends.
        expect(fn () => app(ConfigureWhatsAppNotifications::class)->guestBookingConfirmation($owner, false))
            ->toThrow(EntitlementRequired::class);

        $this->grantWhatsApp();
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');

        // On by default, but nothing is connected: the card says so.
        $this->actingAs($owner);
        $html = (string) $this->get($url)->assertOk()->getContent();

        expect($html)->toContain('Booking confirmations')
            ->toContain('Send booking confirmation to guest customers via WhatsApp')
            ->toContain('Not sending: WhatsApp is not connected.')
            ->toContain('Customer’s language, else EN');

        // Connected, but no approved template mapped: still the truth.
        $account = $this->seedWhatsAppAccount();

        Livewire::actingAs($owner)->test(BookingConfirmations::class)
            ->assertSet('enabled', true)
            ->assertSee('Not sending')
            ->assertSee('No approved confirmation template is set up yet.')
            ->assertSee('Not set up');

        // Mapped: sending — in the languages the platform mapped to a Meta
        // template language. Kurdish is enabled here but not mapped by
        // default, and the card says so, as KU.
        config(['whatsapp.templates.booking_confirmation' => 'booking_confirmation_v1']);

        Livewire::actingAs($owner)->test(BookingConfirmations::class)
            ->assertSee('Sending')
            ->assertSee('booking_confirmation_v1')
            ->assertDontSee('Not sending')
            ->assertSee('Customer’s language, else EN')
            ->assertSeeHtml('data-language="en" data-sendable="1"')
            ->assertSeeHtml('data-language="ar" data-sendable="1"')
            ->assertSeeHtml('data-language="ckb" data-sendable="0"')
            ->assertSee('Not available on WhatsApp')
            ->assertDontSee('CKB');

        expect(app(GuestConfirmationReadiness::class)->blocker())->toBeNull();

        // No enabled language mapped: not sending, and why — never a switch
        // that pretends.
        config(['whatsapp.template_languages' => ['en' => null, 'ar' => null, 'ckb' => null]]);

        Livewire::actingAs($owner)->test(BookingConfirmations::class)
            ->assertSee('Not sending: None of your languages can be sent on WhatsApp yet.')
            ->assertSee('None available')
            ->assertSeeHtml('data-language="en" data-sendable="0"');

        expect(app(GuestConfirmationReadiness::class)->blocker())->toBe('template_language');

        config(['whatsapp.template_languages' => ['en' => 'en', 'ar' => 'ar', 'ckb' => null]]);

        // The owner switches it off: stored, audited with the booleans only.
        Livewire::actingAs($owner)->test(BookingConfirmations::class)
            ->set('enabled', false)
            ->assertSet('noticeTone', 'success')
            ->assertSee('Guest booking confirmations turned off.');

        expect(app(WhatsAppNotificationSettings::class)->guestBookingConfirmation())->toBeFalse()
            ->and(app(GuestConfirmationReadiness::class)->blocker())->toBe('disabled');

        $audit = TenantAuditLog::query()->where('action', 'settings.whatsapp_notifications.updated')->sole();

        expect($audit->before)->toBe(['guest_booking_confirmation' => true])
            ->and($audit->after)->toBe(['guest_booking_confirmation' => false]);

        // settings.view without whatsapp.manage: sees it, cannot change it —
        // the switch snaps back and nothing is stored or audited.
        $manager = $this->seedStaffMember(SystemRole::Manager);

        Livewire::actingAs($manager)->test(BookingConfirmations::class)
            ->assertSet('enabled', false)
            ->assertSee('Only people who can manage WhatsApp can change this.')
            ->set('enabled', true)
            ->assertSet('enabled', false)
            ->assertSet('noticeTone', 'danger');

        expect(fn () => app(ConfigureWhatsAppNotifications::class)->guestBookingConfirmation($manager, true))
            ->toThrow(AuthorizationException::class);

        expect(app(WhatsAppNotificationSettings::class)->guestBookingConfirmation())->toBeFalse()
            ->and(TenantAuditLog::query()->where('action', 'settings.whatsapp_notifications.updated')->count())->toBe(1);

        // The last 30 days, by what was actually OBSERVED: the message's own
        // delivery state wins over the notice's status whenever there is a
        // message (`COALESCE(m.delivery_state, n.status)`) — so a send Meta
        // accepted and later reported refused counts as failed, and one whose
        // outcome was unknown but then observed sent counts as sent.
        $now = CarbonImmutable::now()->utc();

        $thread = Conversation::query()->create([
            'channel' => ConversationChannel::WhatsApp,
            'whatsapp_account_id' => $account->getKey(),
            'contact_phone' => '+9647501230000',
            'status' => ConversationStatus::AiActive,
            'locale' => 'en',
        ]);

        $message = static fn (DeliveryState $state, ?string $code = null): int => (int) Message::query()->create([
            'conversation_id' => $thread->getKey(),
            'direction' => MessageDirection::Outbound,
            'author_type' => MessageAuthor::System,
            'body' => 'Booking confirmed',
            'delivery_state' => $state,
            'failure_code' => $code,
            'template_name' => 'booking_confirmation_v1',
        ])->getKey();

        foreach ([
            // notice status          reason          message (state, code), or none
            [NoticeStatus::Sent, null, [DeliveryState::Sent, null]],
            [NoticeStatus::Skipped, 'no_template', null],
            [NoticeStatus::Failed, 'meta_132001', null],
            // Unknown at send time, then observed sent: counted SENT.
            [NoticeStatus::Unknown, 'transport', [DeliveryState::Sent, null]],
            [NoticeStatus::Pending, null, null],
            // Accepted at send time, refused later by status callback:
            // counted FAILED, and it is the latest issue.
            [NoticeStatus::Sent, null, [DeliveryState::Failed, 'meta_131049']],
        ] as [$status, $reason, $delivery]) {
            WhatsAppOutboundNotice::query()->create([
                'purpose' => NoticePurpose::BookingConfirmation,
                'source_type' => 'appointment',
                'source_uuid' => (string) Str::uuid(),
                'conversation_id' => $delivery === null ? null : $thread->getKey(),
                'message_id' => $delivery === null ? null : $message(...$delivery),
                'status' => $status,
                'reason' => $reason,
                'attempts' => $status === NoticeStatus::Skipped ? 0 : 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // By notice status alone this would be sent 2 · failed 1 · unconfirmed 2.
        $summary = app(GuestConfirmationReadiness::class)->summary();

        expect($summary['counts'])->toBe(['sent' => 2, 'failed' => 2, 'unconfirmed' => 1, 'skipped' => 1])
            ->and($summary['last_issue']['state'] ?? null)->toBe('failed')
            ->and($summary['last_issue']['reason'] ?? null)->toBe('meta_131049');

        $html = (string) $this->get($url)->assertOk()->getContent();

        expect($html)->toContain('Last 30 days')
            ->toContain('Last issue')
            ->toContain('Not delivered')
            ->toContain('meta_131049')
            // Only the LATEST issue is shown; the older refusal is not.
            ->not->toContain('meta_132001');

        foreach (['ar', 'ckb'] as $locale) {
            $this->get("http://{$slug}.localhost:8000/manager/settings/whatsapp?locale={$locale}")
                ->assertOk()
                ->assertSee(__('manager_whatsapp.confirmations.title', [], $locale));
        }

        // Kurdish as the center's primary language, still unmapped: the
        // fallback is the first enabled language that can be sent, and KU is
        // shown as not available — never as its code.
        app()->setLocale('en');
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'ckb');

        Livewire::actingAs($owner)->test(BookingConfirmations::class)
            ->assertSee('Customer’s language, else EN')
            ->assertSeeHtml('data-language="ckb" data-sendable="0"')
            ->assertDontSee('CKB');

        // Once the platform maps it (a code chosen by this test, not a claim
        // about Meta's list), the primary is the fallback, labelled KU.
        config(['whatsapp.template_languages.ckb' => 'ckb']);

        Livewire::actingAs($owner)->test(BookingConfirmations::class)
            ->assertSee('Customer’s language, else KU')
            ->assertSeeHtml('data-language="ckb" data-sendable="1"')
            ->assertDontSee('CKB');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
