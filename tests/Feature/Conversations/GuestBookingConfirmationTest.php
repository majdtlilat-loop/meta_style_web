<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\BookingResult;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Events\AppointmentConfirmed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Conversations\Application\Actions\ConfigureWhatsAppNotifications;
use App\Modules\Conversations\Application\GuestBookingConfirmations;
use App\Modules\Conversations\Application\GuestConfirmationReadiness;
use App\Modules\Conversations\Application\GuestConfirmationReconciler;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Enums\DeliveryState;
use App\Modules\Conversations\Domain\Enums\MessageAuthor;
use App\Modules\Conversations\Domain\Enums\NoticeStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppOutboundNotice;
use App\Modules\Conversations\Infrastructure\Providers\MetaWhatsAppCloudProvider;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Models\Notification;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| A guest's confirmed booking, told to them on WhatsApp
|--------------------------------------------------------------------------
|
| docs/25-WHATSAPP.md §22. A customer with no account whose booking is
| CONFIRMED is sent the center's approved confirmation template — once, after
| the commit, from the booking record, through the channel's own outbound
| path. A provider failure never touches the booking; a retry never sends a
| second copy of anything that may have arrived. Meta is the recorded HTTP
| fake (tests/TestCase.php), never the internet.
|
*/

const GWA_TEMPLATE = 'booking_confirmation_v1';

/**
 * Every request the Cloud API adapter made in this test.
 *
 * @return list<Request>
 */
function gwaSends(): array
{
    return array_values(array_map(
        static fn (array $pair): Request => $pair[0],
        Http::recorded(static fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'))->all(),
    ));
}

/**
 * The template body parameters of one recorded send, in order.
 *
 * @return list<string>
 */
function gwaParameters(Request $request): array
{
    $components = $request->data()['template']['components'] ?? [];

    return array_values(array_map(
        static fn (array $parameter): string => (string) $parameter['text'],
        $components[0]['parameters'] ?? [],
    ));
}

/**
 * A guest booking made by staff, not yet confirmed.
 *
 * @param  array<string, mixed>  $seed
 * @param  list<BookingLine>|null  $lines
 */
function gwaGuestBooking(array $seed, User $user, string $phone, string $time, ?array $lines = null, string $name = 'Sara Ahmed'): Appointment
{
    return gwaGuestBookingResult($seed, $user, $phone, $time, $lines, $name)->appointment;
}

/**
 * The same, with the one-time verification code the booking returned.
 *
 * @param  array<string, mixed>  $seed
 * @param  list<BookingLine>|null  $lines
 */
function gwaGuestBookingResult(array $seed, User $user, string $phone, string $time, ?array $lines = null, string $name = 'Sara Ahmed'): BookingResult
{
    $branch = $seed['branch'];

    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $branch->uuid,
            lines: $lines ?? [new BookingLine(serviceUuid: $seed['service']->uuid)],
            startsAt: CarbonImmutable::parse(CarbonImmutable::now()->addDay()->toDateString().' '.$time, $branch->timezone)->utc(),
            customer: CustomerRef::details($name, $phone),
        ),
        BookingActor::staff($user),
    );
}

function gwaConfirm(Appointment $appointment, User $user): Appointment
{
    return app(TransitionAppointment::class)($appointment, AppointmentStatus::Confirmed, BookingActor::staff($user));
}

it('confirms a guest booking on WhatsApp once, after the commit, with every service and the real booking number', function (): void {
    $center = $this->registerCenter('Salon Gwa', 'owner@gwa-once.test');

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        config(['whatsapp.templates.booking_confirmation' => GWA_TEMPLATE]);

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->seedWhatsAppAccount();

        /** @var Branch $branch */
        $branch = $seed['branch'];
        $branch->forceFill(['phone' => '+9647701112233'])->save();

        $beard = $this->seedService('Beard trim', 20, 10000, $seed['employee']);

        $booked = gwaGuestBookingResult($seed, $owner, '0750 123 4567', '10:00', [
            // Asked for Ahmed by name: named in the confirmation.
            new BookingLine(serviceUuid: $seed['service']->uuid, employeeUuid: $seed['employee']->uuid),
            // "Anyone available": the engine's pick is not promised to the customer.
            new BookingLine(serviceUuid: $beard->uuid),
        ]);
        $appointment = $booked->appointment;
        // The one-time secret this booking was given (docs/24 §3). Pinned, so
        // "it was not sent" below cannot pass because there was none.
        $code = (string) $booked->verificationCode;

        expect($code)->not->toBe('');

        // Booking is not confirming.
        expect(WhatsAppOutboundNotice::query()->count())->toBe(0)
            ->and(gwaSends())->toBe([]);

        // A confirmation that rolls back sends nothing and records nothing.
        DB::connection('tenant')->beginTransaction();
        gwaConfirm($appointment, $owner);

        expect(WhatsAppOutboundNotice::query()->count())->toBe(0)
            ->and(gwaSends())->toBe([]);

        DB::connection('tenant')->rollBack();

        expect($appointment->fresh()?->status)->toBe(AppointmentStatus::Booked)
            ->and(WhatsAppOutboundNotice::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(gwaSends())->toBe([]);

        // Committed: nothing leaves until the outermost transaction commits.
        DB::connection('tenant')->transaction(function () use ($appointment, $owner): void {
            gwaConfirm($appointment->refresh(), $owner);

            expect(gwaSends())->toBe([])
                ->and(WhatsAppOutboundNotice::query()->count())->toBe(0);
        });

        $sends = gwaSends();

        expect($sends)->toHaveCount(1);

        $request = $sends[0];
        $data = $request->data();
        $appointment->refresh();

        expect($data['type'])->toBe('template')
            ->and($data['to'])->toBe('+9647501234567')
            ->and($data['template']['name'])->toBe(GWA_TEMPLATE)
            ->and($data['template']['language']['code'])->toBe('en')
            ->and(gwaParameters($request))->toBe([
                'Sara Ahmed',
                'Salon Gwa',
                $branch->name->get('en'),
                $appointment->localStart()->locale('en')->isoFormat('dddd D MMMM YYYY'),
                '10:00–10:50',
                'Haircut with Ahmed, Beard trim',
                (string) $appointment->reference,
                '+9647701112233',
            ]);

        // The public reference, never an internal identifier or the secret code.
        $raw = (string) json_encode($data);

        expect((string) $appointment->reference)->toStartWith('B-')
            ->and(str_contains($raw, $appointment->uuid))->toBeFalse('the appointment uuid was sent')
            ->and(str_contains($raw, (string) $appointment->customer?->uuid))->toBeFalse('the customer uuid was sent')
            ->and(str_contains($raw, $code))->toBeFalse('the verification code was sent');

        $notice = WhatsAppOutboundNotice::query()->sole();
        $message = Message::query()->sole();
        $conversation = Conversation::query()->sole();

        expect($notice->status)->toBe(NoticeStatus::Sent)
            ->and($notice->attempts)->toBe(1)
            ->and($notice->source_uuid)->toBe($appointment->uuid)
            ->and($notice->message_id)->toBe($message->id)
            ->and($message->author_type)->toBe(MessageAuthor::System)
            ->and($message->template_name)->toBe(GWA_TEMPLATE)
            ->and($message->body)->toContain((string) $appointment->reference)
            ->and($message->body)->toContain('Haircut with Ahmed, Beard trim')
            ->and(str_contains((string) $message->body, $code))->toBeFalse('the verification code was written into the thread')
            // The thread belongs to the booking's customer and branch; a reply
            // lands in it and is routed like any other message.
            ->and($conversation->customer_id)->toBe($appointment->customer_id)
            ->and($conversation->branch_id)->toBe($appointment->branch_id)
            ->and($conversation->status)->toBe(ConversationStatus::AiActive)
            ->and($conversation->contact_phone)->toBe('+9647501234567');

        // Heard again, and replayed: one decision, one message, one send.
        event(new AppointmentConfirmed((int) $appointment->id, (int) $appointment->branch_id, (int) $appointment->customer_id));
        app(GuestConfirmationReconciler::class)->reconcile(CarbonImmutable::now()->subDays(3));

        expect(WhatsAppOutboundNotice::query()->count())->toBe(1)
            ->and(Message::query()->count())->toBe(1)
            ->and(gwaSends())->toHaveCount(1)
            // Nothing created a customer or an account.
            ->and(Customer::query()->count())->toBe(1)
            ->and(DB::connection('tenant')->table('customer_accounts')->count())->toBe(0);

        // A later confirmation to the same number joins the open thread.
        $second = gwaGuestBooking($seed, $owner, '0750 123 4567', '14:00');
        gwaConfirm($second, $owner);

        expect(Conversation::query()->count())->toBe(1)
            ->and(Message::query()->count())->toBe(2)
            ->and(WhatsAppOutboundNotice::query()->count())->toBe(2);
    });
});

it('writes in the customer\'s language when the center has it, and leaves registered customers to the in-app notice', function (): void {
    $center = $this->registerCenter('Salon Gwa Lang', 'owner@gwa-lang.test');

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        $this->grantCustomerAccounts();
        config(['whatsapp.templates.booking_confirmation' => GWA_TEMPLATE]);

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->seedWhatsAppAccount();

        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');

        // Arabic, which the center has enabled.
        $arabic = gwaGuestBooking($seed, $owner, '0750 123 4501', '10:00', name: 'Layla');
        $arabic->customer?->forceFill(['preferred_locale' => 'ar'])->save();
        gwaConfirm($arabic, $owner);

        // Kurdish, which it has not: the center's primary language instead.
        $kurdish = gwaGuestBooking($seed, $owner, '0750 123 4502', '11:00', name: 'Hawre');
        $kurdish->customer?->forceFill(['preferred_locale' => 'ckb'])->save();
        gwaConfirm($kurdish, $owner);

        $sends = gwaSends();

        expect($sends)->toHaveCount(2)
            ->and($sends[0]->data()['template']['language']['code'])->toBe('ar')
            ->and(gwaParameters($sends[0])[5])->toBe('قص شعر')
            ->and($sends[1]->data()['template']['language']['code'])->toBe('en');

        $arabicMessage = Message::query()
            ->whereKey(WhatsAppOutboundNotice::query()->where('source_uuid', $arabic->uuid)->value('message_id'))
            ->sole();

        expect($arabicMessage->body)->toContain('تم تأكيد حجزك')
            ->and(WhatsAppOutboundNotice::query()->where('source_uuid', $kurdish->uuid)->value('locale'))->toBe('en');

        // A registered customer: the existing in-app notice, and no WhatsApp.
        $member = $this->seedCustomer('Registered Rana', '0750 123 4503');
        $this->seedCustomerAccount($member);
        $booked = $this->bookFor($seed, $owner, $member, time: '12:00');
        gwaConfirm($booked, $owner);

        expect(gwaSends())->toHaveCount(2)
            ->and(WhatsAppOutboundNotice::query()->where('source_uuid', $booked->uuid)->exists())->toBeFalse()
            ->and(Notification::query()
                ->where('type', NotificationType::AppointmentConfirmed->value)
                ->where('source_uuid', $booked->uuid)
                ->count())->toBe(1);
    });
});

it('sends only in a language mapped to a Meta template language, and skips when the center has none', function (): void {
    $center = $this->registerCenter('Salon Gwa Codes', 'owner@gwa-codes.test');

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        config(['whatsapp.templates.booking_confirmation' => GWA_TEMPLATE]);

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->seedWhatsAppAccount();

        $noticeLocale = static fn (Appointment $appointment): mixed => WhatsAppOutboundNotice::query()
            ->where('source_uuid', $appointment->uuid)
            ->value('locale');

        // The platform default: English and Arabic mapped, Kurdish NOT (Meta
        // template support for it is unverified).
        expect(config('whatsapp.template_languages'))->toBe(['en' => 'en', 'ar' => 'ar', 'ckb' => null]);

        // The center publishes in all three, Arabic first.
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'ar');

        // A guest who prefers Kurdish — enabled here, but not sendable — is
        // written to in the center's primary language, never under `ckb`.
        $kurdish = gwaGuestBooking($seed, $owner, '0750 123 4531', '10:00', name: 'Hawre');
        $kurdish->customer?->forceFill(['preferred_locale' => 'ckb'])->save();
        gwaConfirm($kurdish, $owner);

        $sends = gwaSends();

        expect($sends)->toHaveCount(1)
            ->and($sends[0]->data()['template']['language']['code'])->toBe('ar')
            ->and(gwaParameters($sends[0])[5])->toBe('قص شعر')
            ->and($noticeLocale($kurdish))->toBe('ar');

        // The MAPPED code is what Meta receives, never the app locale as is;
        // the parameters stay in the app language it maps from.
        config(['whatsapp.template_languages.en' => 'en_US']);

        $english = gwaGuestBooking($seed, $owner, '0750 123 4532', '11:00', name: 'Sara');
        $english->customer?->forceFill(['preferred_locale' => 'en'])->save();
        gwaConfirm($english, $owner);

        $sends = gwaSends();

        expect($sends)->toHaveCount(2)
            ->and($sends[1]->data()['template']['language']['code'])->toBe('en_US')
            ->and(gwaParameters($sends[1])[5])->toBe('Haircut')
            ->and($noticeLocale($english))->toBe('en');

        // Primary not mapped either: any enabled language that is.
        config(['whatsapp.template_languages.ar' => null]);

        $fallback = gwaGuestBooking($seed, $owner, '0750 123 4533', '12:00', name: 'Dilan');
        $fallback->customer?->forceFill(['preferred_locale' => 'ckb'])->save();
        gwaConfirm($fallback, $owner);

        $sends = gwaSends();

        expect($sends)->toHaveCount(3)
            ->and($sends[2]->data()['template']['language']['code'])->toBe('en_US')
            ->and(gwaParameters($sends[2])[5])->toBe('Haircut')
            ->and($noticeLocale($fallback))->toBe('en');

        // Nothing mapped: a recorded skip with the reason, and nothing sent.
        config(['whatsapp.template_languages' => ['en' => null, 'ar' => null, 'ckb' => null]]);

        expect(app(GuestConfirmationReadiness::class)->blocker())->toBe('template_language');

        $unsendable = gwaGuestBooking($seed, $owner, '0750 123 4534', '13:00', name: 'Nobody Mapped');
        gwaConfirm($unsendable, $owner);

        $skipped = WhatsAppOutboundNotice::query()->where('source_uuid', $unsendable->uuid)->sole();

        expect($skipped->status)->toBe(NoticeStatus::Skipped)
            ->and($skipped->reason)->toBe('template_language')
            ->and($skipped->attempts)->toBe(0)
            ->and($skipped->message_id)->toBeNull()
            ->and($unsendable->fresh()?->status)->toBe(AppointmentStatus::Confirmed)
            ->and(gwaSends())->toHaveCount(3)
            ->and(Message::query()->count())->toBe(3);
    });
});

it('keeps the booking when the provider refuses, alerts the staff, retries only what is safe, and never twice', function (): void {
    $center = $this->registerCenter('Salon Gwa Retry', 'owner@gwa-retry.test');

    $this->asCenter($center['tenant'], function (): void {
        $this->grantWhatsApp();
        config(['whatsapp.templates.booking_confirmation' => GWA_TEMPLATE]);

        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->seedWhatsAppAccount();
        $reconciler = app(GuestConfirmationReconciler::class);
        $since = CarbonImmutable::now()->subDays(3);

        // Meta refuses: a 4xx with its own error code.
        $this->whatsAppResponse = Http::response(['error' => ['message' => 'Template name does not exist', 'code' => 132001]], 400);

        $refused = gwaGuestBooking($seed, $owner, '0750 123 4511', '10:00');
        $confirmed = gwaConfirm($refused, $owner);

        $notice = WhatsAppOutboundNotice::query()->where('source_uuid', $refused->uuid)->sole();

        expect($confirmed->status)->toBe(AppointmentStatus::Confirmed)
            ->and($refused->fresh()?->status)->toBe(AppointmentStatus::Confirmed)
            ->and($notice->status)->toBe(NoticeStatus::Failed)
            ->and($notice->reason)->toBe('meta_132001')
            ->and($notice->attempts)->toBe(1)
            // The staff who can fix WhatsApp are told, with a safe code only.
            ->and(Notification::query()->where('type', NotificationType::WhatsAppProviderFailed->value)->count())->toBe(1)
            ->and(str_contains((string) json_encode(Notification::query()->where('type', NotificationType::WhatsAppProviderFailed->value)->value('params')), 'does not exist'))
            ->toBeFalse('the provider message reached the inbox');

        // Still refused: the retry is a new attempt on the SAME notice.
        expect($reconciler->reconcile($since))->toBe(1);

        $notice->refresh();

        expect($notice->status)->toBe(NoticeStatus::Failed)
            ->and($notice->attempts)->toBe(2)
            ->and(WhatsAppOutboundNotice::query()->count())->toBe(1)
            ->and(gwaSends())->toHaveCount(2);

        // Meta accepts the third attempt; after that there is nothing to retry.
        $this->whatsAppResponse = null;

        expect($reconciler->reconcile($since))->toBe(1);

        $notice->refresh();

        expect($notice->status)->toBe(NoticeStatus::Sent)
            ->and($notice->attempts)->toBe(3)
            ->and($reconciler->reconcile($since))->toBe(0)
            ->and(gwaSends())->toHaveCount(3)
            ->and(Appointment::query()->count())->toBe(1);

        // A 5xx is an UNKNOWN outcome — it may have arrived — and is never retried.
        $this->whatsAppResponse = Http::response(['error' => ['message' => 'Service unavailable']], 503);

        $unclear = gwaGuestBooking($seed, $owner, '0750 123 4512', '12:00');
        gwaConfirm($unclear, $owner);

        $unknown = WhatsAppOutboundNotice::query()->where('source_uuid', $unclear->uuid)->sole();

        expect($unknown->status)->toBe(NoticeStatus::Unknown)
            ->and($unclear->fresh()?->status)->toBe(AppointmentStatus::Confirmed)
            ->and(gwaSends())->toHaveCount(4);

        $this->whatsAppResponse = null;
        $reconciler->reconcile($since);

        expect($unknown->fresh()?->status)->toBe(NoticeStatus::Unknown)
            ->and(gwaSends())->toHaveCount(4);

        // A timeout is the same: the request may have reached Meta. The
        // booking stands, the notice is `unknown`, and nothing resends it.
        $this->whatsAppResponse = new RejectedPromise(new ConnectException(
            'cURL error 28: Operation timed out',
            new Psr7Request('POST', 'https://graph.facebook.com/v25.0/106540352242922/messages'),
        ));

        $slow = gwaGuestBooking($seed, $owner, '0750 123 4516', '12:30');

        expect(gwaConfirm($slow, $owner)->status)->toBe(AppointmentStatus::Confirmed);

        $timedOut = WhatsAppOutboundNotice::query()->where('source_uuid', $slow->uuid)->sole();

        expect($timedOut->status)->toBe(NoticeStatus::Unknown)
            ->and($timedOut->reason)->toBe('transport')
            ->and($timedOut->attempts)->toBe(1)
            ->and(Message::query()->whereKey($timedOut->message_id)->sole()->delivery_state)->toBe(DeliveryState::Unknown)
            ->and($slow->fresh()?->status)->toBe(AppointmentStatus::Confirmed)
            // The one attempt (Laravel records a failed connection too).
            ->and(gwaSends())->toHaveCount(5);

        // Meta answers again; the timed-out one is still never resent.
        $this->whatsAppResponse = null;
        $reconciler->reconcile($since);

        expect($timedOut->fresh()?->status)->toBe(NoticeStatus::Unknown)
            ->and($timedOut->fresh()?->attempts)->toBe(1)
            ->and(gwaSends())->toHaveCount(5);

        // A refused notice whose attempts are spent is left alone.
        $this->whatsAppResponse = Http::response(['error' => ['message' => 'Nope', 'code' => 131026]], 400);

        $spent = gwaGuestBooking($seed, $owner, '0750 123 4513', '13:00');
        gwaConfirm($spent, $owner);
        $reconciler->reconcile($since);
        $reconciler->reconcile($since);
        $reconciler->reconcile($since);

        $exhausted = WhatsAppOutboundNotice::query()->where('source_uuid', $spent->uuid)->sole();

        expect($exhausted->status)->toBe(NoticeStatus::Failed)
            ->and($exhausted->attempts)->toBe(GuestBookingConfirmations::MAX_ATTEMPTS)
            ->and(gwaSends())->toHaveCount(5 + GuestBookingConfirmations::MAX_ATTEMPTS);

        // A refused confirmation whose booking is cancelled before the retry:
        // a cancelled booking is never "confirmed" to anybody.
        $called = gwaGuestBooking($seed, $owner, '0750 123 4515', '16:00');
        gwaConfirm($called, $owner);

        $this->whatsAppResponse = null;
        app(TransitionAppointment::class)($called->refresh(), AppointmentStatus::Cancelled, BookingActor::staff($owner), ['reason' => 'Customer called']);
        $reconciler->reconcile($since);

        $withdrawn = WhatsAppOutboundNotice::query()->where('source_uuid', $called->uuid)->sole();

        expect($withdrawn->status)->toBe(NoticeStatus::Failed)
            ->and($withdrawn->attempts)->toBe(1)
            ->and(gwaSends())->toHaveCount(6 + GuestBookingConfirmations::MAX_ATTEMPTS);

        // A callback that never ran — the process died after the commit — is
        // replayed by the reconciler, once.
        $missed = gwaGuestBooking($seed, $owner, '0750 123 4514', '15:00');
        Event::fakeFor(fn () => gwaConfirm($missed, $owner), [AppointmentConfirmed::class]);

        expect(WhatsAppOutboundNotice::query()->where('source_uuid', $missed->uuid)->exists())->toBeFalse();

        $reconciler->reconcile($since);
        $reconciler->reconcile($since);

        expect(WhatsAppOutboundNotice::query()->where('source_uuid', $missed->uuid)->sole()->status)->toBe(NoticeStatus::Sent)
            ->and(gwaSends())->toHaveCount(7 + GuestBookingConfirmations::MAX_ATTEMPTS);
    });
});

it('sends nothing, and records why, when the channel cannot or may not send', function (): void {
    $center = $this->registerCenter('Salon Gwa Skip', 'owner@gwa-skip.test');

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $reasonFor = static fn (Appointment $appointment): ?string => WhatsAppOutboundNotice::query()
            ->where('source_uuid', $appointment->uuid)
            ->where('status', NoticeStatus::Skipped->value)
            ->value('reason');

        // Not entitled: decided (and recorded) as not sent — so gaining the
        // channel later can never replay it as news.
        $unowned = gwaGuestBooking($seed, $owner, '0750 123 4521', '09:00');
        gwaConfirm($unowned, $owner);

        expect($reasonFor($unowned))->toBe('channel_inactive');

        $this->grantWhatsApp();

        // Owned, but no account yet.
        $noAccount = gwaGuestBooking($seed, $owner, '0750 123 4522', '09:30');
        gwaConfirm($noAccount, $owner);

        expect($reasonFor($noAccount))->toBe('not_connected');

        // An account a manager switched off.
        $account = $this->seedWhatsAppAccount(enabled: false);
        $off = gwaGuestBooking($seed, $owner, '0750 123 4523', '10:00');
        gwaConfirm($off, $owner);

        expect($reasonFor($off))->toBe('account_off');

        // On, but its provider is no longer offered by the platform.
        $account->forceFill(['enabled' => true, 'provider' => 'retired_provider'])->save();
        $retired = gwaGuestBooking($seed, $owner, '0750 123 4528', '13:00');
        gwaConfirm($retired, $owner);

        expect($reasonFor($retired))->toBe('provider_unavailable');

        // On, but no approved template mapped (config ships empty).
        $account->forceFill(['provider' => MetaWhatsAppCloudProvider::CODE])->save();
        config(['whatsapp.templates.booking_confirmation' => null]);
        $noTemplate = gwaGuestBooking($seed, $owner, '0750 123 4524', '10:30');
        gwaConfirm($noTemplate, $owner);

        expect($reasonFor($noTemplate))->toBe('no_template');

        // The center's own switch.
        config(['whatsapp.templates.booking_confirmation' => GWA_TEMPLATE]);
        app(ConfigureWhatsAppNotifications::class)->guestBookingConfirmation($owner, false);
        $disabled = gwaGuestBooking($seed, $owner, '0750 123 4525', '11:00');
        gwaConfirm($disabled, $owner);

        expect($reasonFor($disabled))->toBe('disabled');

        app(ConfigureWhatsAppNotifications::class)->guestBookingConfirmation($owner, true);

        // A customer with no number, one with an unusable one, one who opted out.
        $noPhone = $this->seedCustomer('No Phone', null);
        $withoutPhone = $this->bookFor($seed, $owner, $noPhone, time: '11:30');
        gwaConfirm($withoutPhone, $owner);

        $broken = gwaGuestBooking($seed, $owner, '0750 123 4526', '12:00', name: 'Broken Number');
        $broken->customer?->forceFill(['phone' => 'not-a-number'])->save();
        gwaConfirm($broken, $owner);

        $optedOut = gwaGuestBooking($seed, $owner, '0750 123 4527', '12:30', name: 'Quiet Please');
        $optedOut->customer?->forceFill(['allow_operational_messages' => false])->save();
        gwaConfirm($optedOut, $owner);

        expect($reasonFor($withoutPhone))->toBe('no_phone')
            ->and($reasonFor($broken))->toBe('no_phone')
            ->and($reasonFor($optedOut))->toBe('opted_out');

        // Every booking is confirmed; not one message was sent or stored.
        expect(Appointment::query()->where('status', AppointmentStatus::Confirmed->value)->count())->toBe(9)
            ->and(WhatsAppOutboundNotice::query()->count())->toBe(9)
            ->and(gwaSends())->toBe([])
            ->and(Message::query()->count())->toBe(0)
            ->and(Conversation::query()->count())->toBe(0);

        // A skip is a decision, not a failure: the reconciler never sends it later.
        app(GuestConfirmationReconciler::class)->reconcile(CarbonImmutable::now()->subDays(3));

        expect(gwaSends())->toBe([]);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
