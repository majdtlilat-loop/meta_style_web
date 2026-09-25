<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Kernel\Usage\Usage;
use App\Modules\Conversations\Application\Actions\ReceiveWhatsAppWebhook;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Domain\Models\AiRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeAiProvider;

/*
|--------------------------------------------------------------------------
| Phase 13 staff notifications
|--------------------------------------------------------------------------
|
| docs/23-NOTIFICATIONS.md · docs/25-WHATSAPP.md §13 · ADR-067.
|
| Conversations and `Kernel\Usage` emit FACTS; this module listens. The rule
| that matters is the direction: a notification failure can lose a notification
| and must never lose the thing it was about.
|
*/

/**
 * @return array<int, object>
 */
function staffNotifications(NotificationType $type): array
{
    return DB::connection('tenant')->table('notifications')
        ->where('type', $type->value)
        ->orderBy('id')
        ->get()
        ->all();
}

it('tells the staff who can take over that a conversation is waiting', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->ownerWithCatalogAccess();

        // No assistant entitlement, so the first message hands straight off.
        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'Is anyone there?')
        ));

        $notifications = staffNotifications(NotificationType::ConversationTakeoverRequested);

        expect($notifications)->toHaveCount(1);

        $notification = $notifications[0];
        $params = (array) json_decode((string) $notification->params, true);

        expect($notification->source_type)->toBe('conversation')
            // Keyed on the CONVERSATION, so a thread that bounces in and out of
            // `human_requested` over its life pages the desk once (§10).
            ->and($notification->source_uuid)->toBe($this->conversationFor()?->uuid)
            ->and($params['reason'])->toBe('assistant_disabled');

        // The reason code and the name — never the message text (§7).
        $serialised = (string) $notification->params;

        expect(str_contains($serialised, 'Is anyone there?'))->toBeFalse('the message body is in the notification')
            ->and(str_contains($serialised, '9647501234567'))->toBeFalse('the phone number is in the notification');

        // Delivered to the owner, who holds `conversation.takeover`.
        expect(DB::connection('tenant')->table('notification_recipients')
            ->where('notification_id', $notification->id)->count())->toBe(1);
    });
});

it('pages the desk once, however many times a thread asks for a person', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->ownerWithCatalogAccess();

        foreach (['first', 'second', 'third'] as $index => $text) {
            app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
                $this->metaTextNotification(messageId: 'wamid.ASK_'.$index, text: $text)
            ));
        }

        expect(Message::query()->where('direction', 'inbound')->count())->toBe(3)
            /*
             * One notification. `unique(type, source_type, source_uuid)` is the
             * mechanism; nothing asks "have I already told them" (§10).
             */
            ->and(staffNotifications(NotificationType::ConversationTakeoverRequested))->toHaveCount(1);
    });
});

it('tells whoever can fix credentials when the provider refuses a message', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->ownerWithCatalogAccess();

        // Meta answering 400 — a REFUSAL, which is actionable.
        $this->whatsAppResponse = Http::response(
            ['error' => ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100]],
            400,
        );

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'Hello')
        ));

        $notifications = staffNotifications(NotificationType::WhatsAppProviderFailed);

        expect($notifications)->toHaveCount(1);

        $params = (array) json_decode((string) $notifications[0]->params, true);

        // A SAFE code, never the provider's own message — which can echo the
        // customer's text straight back into an inbox.
        expect($params['code'])->toBe('meta_100')
            ->and(str_contains((string) $notifications[0]->params, 'Invalid parameter'))
            ->toBeFalse('the provider message reached the inbox');

        // And the outbound row says what happened.
        expect(Message::query()->where('direction', 'outbound')->first()?->delivery_state->value)
            ->toBe('failed');
    });
});

it('says nothing when a send outcome is merely unknown', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $this->ownerWithCatalogAccess();

        // A 503: Meta FAILING rather than refusing. The message may still have
        // been queued on their side, which is the whole reason `unknown` exists.
        $this->whatsAppResponse = Http::response('', 503);

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'Hello')
        ));

        $outbound = Message::query()->where('direction', 'outbound')->first();

        expect($outbound?->delivery_state->value)->toBe('unknown')
            // No `sent_at`: stamping one would record a delivery nobody saw.
            ->and($outbound?->sent_at)->toBeNull()
            /*
             * NOT paged. An `unknown` is visible in the thread, and alerting on
             * every transient blip trains staff to ignore the alert that means
             * the integration is genuinely broken (§13).
             */
            ->and(staffNotifications(NotificationType::WhatsAppProviderFailed))->toBe([]);
    });
});

it('warns once per threshold as an allowance runs down, then says it is gone', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantAssistant();
        $this->ownerWithCatalogAccess();
        $this->setAllowance('ai_runs', 10);

        $usage = app(Usage::class);

        // Seven of ten crosses 70%.
        for ($i = 0; $i < 7; $i++) {
            $usage->consume('ai_runs', 'ai_run', 'run-'.$i);
        }

        expect(staffNotifications(NotificationType::AiQuotaWarning))->toHaveCount(1);

        // Eight and nine stay inside the same band — and say nothing more.
        $usage->consume('ai_runs', 'ai_run', 'run-7');

        expect(staffNotifications(NotificationType::AiQuotaWarning))->toHaveCount(1);

        // Nine of ten crosses 85%: a second, distinct threshold.
        $usage->consume('ai_runs', 'ai_run', 'run-8');

        expect(staffNotifications(NotificationType::AiQuotaWarning))->toHaveCount(2);

        // Ten of ten is exhaustion, and a different TYPE — an `important` one.
        $usage->consume('ai_runs', 'ai_run', 'run-9');

        $exhausted = staffNotifications(NotificationType::AiQuotaExhausted);

        expect($exhausted)->toHaveCount(1);

        $params = (array) json_decode((string) $exhausted[0]->params, true);

        expect($params['percent'])->toBe(100)
            ->and($params['resource'])->toBe('ai_runs')
            ->and($params['allowance'])->toBe(10);

        expect(NotificationType::AiQuotaExhausted->severity()->value)->toBe('important')
            ->and(NotificationType::AiQuotaWarning->severity()->value)->toBe('normal');
    });
});

it('never warns about a resource that cannot run out', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $this->ownerWithCatalogAccess();

        // A stated allowance on a METERED resource, blown straight through.
        $this->setAllowance('wa_outbound', 2);

        $usage = app(Usage::class);

        foreach (range(1, 6) as $n) {
            $usage->meter('wa_outbound', 'wa_message', 'message-'.$n);
        }

        expect($usage->counter('wa_outbound')->used)->toBe(6)
            /*
             * WhatsApp volume has no ENFORCED allowance to run out of, so a
             * "threshold" there would be a warning about nothing (§13).
             */
            ->and(staffNotifications(NotificationType::AiQuotaWarning))->toBe([])
            ->and(staffNotifications(NotificationType::AiQuotaExhausted))->toBe([]);
    });
});

it('keeps the conversation true when the notification cannot be written', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantAssistant();
        $account = $this->seedWhatsAppAccount();
        $this->ownerWithCatalogAccess();

        app()->instance(
            AiProviderRegistry::class,
            app(AiProviderRegistry::class)->with((new FakeAiProvider)->willFail('provider_error')),
        );

        /*
         * FAULT INJECTION at the connection, because `insertOrIgnore` bypasses
         * model events. Every write to `notifications` fails from here on; the
         * conversation's own writes are untouched.
         */
        $down = true;

        DB::connection('tenant')->beforeExecuting(function (string $query) use (&$down): void {
            if ($down && str_contains($query, 'notifications') && str_starts_with(ltrim($query), 'insert')) {
                throw new RuntimeException('the notifications table is unavailable');
            }
        });

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'Please help')
        ));

        $down = false;

        /*
         * THE ASSERTION. The inbound message is stored, the conversation is
         * waiting for a person, and the AI run is recorded — all of it
         * committed before the notification was ever attempted. A notification
         * can be lost; the thing it was about cannot (ADR-067).
         */
        $conversation = $this->conversationFor();

        expect($conversation)->not->toBeNull()
            ->and($conversation?->status)->toBe(ConversationStatus::HumanRequested)
            ->and(Message::query()->where('direction', 'inbound')->count())->toBe(1)
            ->and(AiRun::query()->count())->toBe(1);

        // And the notification really did fail to write, so the test is
        // proving something rather than passing because nothing was tried.
        expect(staffNotifications(NotificationType::ConversationTakeoverRequested))->toBe([]);
    });
});

it('targets the staff who hold the permission, in the branch, and nobody else', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $account = $this->seedWhatsAppAccount();
        $owner = $this->ownerWithCatalogAccess();

        // A cashier: signs in, holds no conversation permission.
        $cashier = $this->seedStaffMember(SystemRole::Cashier, name: 'Cashier');

        app(ReceiveWhatsAppWebhook::class)($account->uuid, $this->signedEnvelope(
            $this->metaTextNotification(text: 'Hello')
        ));

        $notification = staffNotifications(NotificationType::ConversationTakeoverRequested)[0];

        $recipients = DB::connection('tenant')->table('notification_recipients')
            ->where('notification_id', $notification->id)
            ->pluck('recipient_id')
            ->all();

        // By PERMISSION, never by role name (§5).
        expect($recipients)->toContain($owner->getKey())
            ->and(in_array($cashier->getKey(), $recipients, true))
            ->toBeFalse('a cashier was told about a conversation they cannot take over');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
