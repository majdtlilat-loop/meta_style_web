<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Localization\TenantLocales;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Booking\Contracts\BookingConfirmationFacts;
use App\Modules\Booking\Data\BookingConfirmationData;
use App\Modules\Conversations\Domain\Data\OutboundMessage;
use App\Modules\Conversations\Domain\Enums\ConversationChannel;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Conversations\Domain\Enums\DeliveryState;
use App\Modules\Conversations\Domain\Enums\MessageAuthor;
use App\Modules\Conversations\Domain\Enums\NoticePurpose;
use App\Modules\Conversations\Domain\Enums\NoticeStatus;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\Modules\Conversations\Domain\Models\WhatsAppOutboundNotice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A guest's confirmed booking, told to them on WhatsApp
 * (docs/25-WHATSAPP.md §22).
 *
 * ## Who
 *
 * A GUEST — a customer with no `customer_accounts` row (ADR-041). A registered
 * customer already gets the in-app notification Notifications writes for the
 * same event; sending both would be the duplicate the product rules forbid.
 * A CRM customer is not an account, and nothing here creates either: the
 * customer already exists (the booking made it), and a thread is not a login.
 *
 * ## What it knows about the booking
 *
 * Only what the Booking module's `BookingConfirmationFacts` contract returns
 * (`BookingConfirmationData`) — never a Booking, Customer or Employee model.
 * The channel can describe a booking; it cannot hold, and so cannot change,
 * one (docs/04-MODULE-BOUNDARIES.md §2).
 *
 * ## When
 *
 * Only for a booking that IS confirmed, and only after that commit
 * ({@see Listeners\ConfirmGuestBookingOnWhatsApp} runs through `AfterCommit`).
 * A confirmation that rolled back sends nothing; a provider failure can never
 * undo the booking, because the booking committed before anything here ran.
 *
 * ## Exactly once
 *
 * The decision is a row: `unique(purpose, source_type, source_uuid)` plus
 * `insertOrIgnore`. The event heard twice or the reconciler racing the
 * listener meet the same row and one of them stops. A retry is claimed by one
 * conditional UPDATE (`failed` → `pending`) whose affected-row count is the
 * answer, so two retry passes cannot both send. `unknown` and a stale
 * `pending` are never retried: Meta's send takes no idempotency key, and the
 * customer may already have the message (§9).
 *
 * ## How
 *
 * Through the channel's own machinery, never beside it: the approved template
 * for the purpose (business-initiated messages are never free-form text, §14),
 * in the Meta template language {@see GuestConfirmationReadiness} resolves for
 * the customer, {@see OutboundMessages} (persist first, send second, settle;
 * its flood guard, its metering, its `ProviderSendFailed` → the staff alert),
 * in the thread this center already has with that number — or a new one,
 * attached to the booking's customer and branch, so a reply lands where the
 * confirmation is.
 */
final class GuestBookingConfirmations
{
    /** The first attempt and two retries. */
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly BookingConfirmationFacts $bookings,
        private readonly GuestConfirmationReadiness $readiness,
        private readonly OutboundMessages $outbound,
        private readonly BookingConfirmationContent $content,
        private readonly TenantLocales $locales,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * The first decision for a booking that was just confirmed.
     *
     * The decision is recorded even when the center does not own the channel
     * (`skipped` / `channel_inactive`): a booking confirmed during that gap is
     * decided, so regaining the channel can never replay it as news.
     *
     * @return WhatsAppOutboundNotice|null null when nothing applies (the booking
     *                                     is not confirmed, the customer is
     *                                     registered) or when another pass
     *                                     already decided
     */
    public function confirm(int $appointmentId): ?WhatsAppOutboundNotice
    {
        $booking = $this->bookings->find($appointmentId);

        if (! $booking instanceof BookingConfirmationData || ! $this->applies($booking)) {
            return null;
        }

        $notice = $this->claim($booking);

        return $notice instanceof WhatsAppOutboundNotice ? $this->attempt($notice, $booking) : null;
    }

    /**
     * Another attempt at a REFUSED confirmation, while the booking still
     * stands and is still ahead.
     */
    public function retry(WhatsAppOutboundNotice $notice): ?WhatsAppOutboundNotice
    {
        if ($this->readiness->blocker() === 'channel_inactive') {
            return null;
        }

        $claimed = WhatsAppOutboundNotice::query()
            ->whereKey($notice->getKey())
            ->where('status', NoticeStatus::Failed->value)
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->update(['status' => NoticeStatus::Pending->value, 'updated_at' => CarbonImmutable::now()->utc()]);

        if ($claimed !== 1) {
            return null;
        }

        $notice->refresh();

        $booking = $this->bookings->findByUuid($notice->source_uuid);

        if (! $booking instanceof BookingConfirmationData || ! $this->applies($booking) || $booking->startsAt <= CarbonImmutable::now()->utc()) {
            // Nothing to confirm any more. Put it back exactly as it was.
            $notice->forceFill(['status' => NoticeStatus::Failed])->save();

            return null;
        }

        return $this->attempt($notice, $booking);
    }

    /**
     * Confirmed, and a guest. Everything else is decided — and recorded —
     * by {@see attempt()}.
     */
    private function applies(BookingConfirmationData $booking): bool
    {
        return $booking->isConfirmed() && ! $booking->customerHasAccount;
    }

    /**
     * @return WhatsAppOutboundNotice|null the row THIS call created, or null
     */
    private function claim(BookingConfirmationData $booking): ?WhatsAppOutboundNotice
    {
        $now = CarbonImmutable::now()->utc();

        $inserted = DB::connection('tenant')->table('whatsapp_outbound_notices')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'purpose' => NoticePurpose::BookingConfirmation->value,
            'source_type' => 'appointment',
            'source_uuid' => $booking->uuid,
            'customer_id' => $booking->customerId,
            'branch_id' => $booking->branchId,
            'status' => NoticeStatus::Pending->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted !== 1) {
            return null;
        }

        /** @var WhatsAppOutboundNotice $notice */
        $notice = WhatsAppOutboundNotice::query()
            ->where('purpose', NoticePurpose::BookingConfirmation->value)
            ->where('source_type', 'appointment')
            ->where('source_uuid', $booking->uuid)
            ->firstOrFail();

        return $notice;
    }

    /**
     * Decide, and — when everything allows it — send once.
     */
    private function attempt(WhatsAppOutboundNotice $notice, BookingConfirmationData $booking): WhatsAppOutboundNotice
    {
        $account = $this->readiness->account();
        $language = $this->readiness->templateLanguage($booking->customerLocale);
        // The language the notice is (or would have been) written in.
        $locale = $language['locale'] ?? $this->locales->resolve($booking->customerLocale);

        $reason = $this->readiness->blocker($account)
            ?? ($language === null ? 'template_language' : null)
            ?? $this->customerBlocker($booking);
        $phone = $booking->customerPhone;
        $template = $this->readiness->template();

        if ($reason !== null || ! $account instanceof WhatsAppAccount || $phone === null || $template === null || $language === null) {
            return $this->settle($notice, NoticeStatus::Skipped, $reason ?? 'not_connected', $locale, attempted: false);
        }

        try {
            $rendered = $this->content->render($booking, $this->tenants->require()->name, $language['locale']);
            $conversation = $this->thread($account, $phone, $booking, $language['locale']);
        } catch (Throwable $e) {
            // Nothing left the building: safe to try again later.
            report($e);

            return $this->settle($notice, NoticeStatus::Failed, 'error', $locale);
        }

        try {
            $message = $this->outbound->send(
                $conversation,
                OutboundMessage::template(
                    toPhone: $phone,
                    templateName: $template,
                    // Meta's code for the language, never the app locale as is.
                    templateLocale: $language['code'],
                    parameters: $rendered['parameters'],
                    body: $rendered['body'],
                ),
                MessageAuthor::System,
            );
        } catch (TooManyAttempts) {
            // The outbound flood guard refused before anything was persisted.
            return $this->settle($notice, NoticeStatus::Failed, 'rate_limited', $locale, conversation: $conversation);
        } catch (ConversationFailed) {
            return $this->settle($notice, NoticeStatus::Failed, 'not_configured', $locale, conversation: $conversation);
        } catch (Throwable $e) {
            /*
             * Something failed after the message may have been persisted — and
             * so possibly after the provider accepted it. Never guessed as a
             * refusal: `unknown` is never retried (§9).
             */
            report($e);

            return $this->settle($notice, NoticeStatus::Unknown, 'error', $locale, conversation: $conversation);
        }

        $state = $message->delivery_state ?? DeliveryState::Unknown;

        return $this->settle(
            $notice,
            NoticeStatus::fromDelivery($state),
            $state === DeliveryState::Sent ? null : $message->failure_code,
            $locale,
            conversation: $conversation,
            messageId: (int) $message->getKey(),
        );
    }

    /**
     * Reasons that belong to this customer or this booking, not the channel.
     */
    private function customerBlocker(BookingConfirmationData $booking): ?string
    {
        // Consent the CRM already stores (docs/13 Phase 5 §13): "may receive
        // booking and visit messages".
        if (! $booking->customerAllowsOperationalMessages) {
            return 'opted_out';
        }

        // Booking hands over the E.164 form, or null for none / unusable.
        if ($booking->customerPhone === null) {
            return 'no_phone';
        }

        if ($booking->reference === null || $booking->reference === '') {
            return 'no_reference';
        }

        return null;
    }

    /**
     * The open thread this center already has with the number, or a new one
     * about this booking.
     *
     * A closed thread is never reopened (the router's rule). A new thread
     * starts where an inbound one would — `ai_active` — so a customer's reply
     * is routed exactly as any other message; it carries the booking's
     * customer and branch, which scopes it to the staff of that branch. No
     * customer and no account is created: the customer already exists.
     */
    private function thread(WhatsAppAccount $account, string $phone, BookingConfirmationData $booking, string $locale): Conversation
    {
        /** @var Conversation|null $existing */
        $existing = Conversation::query()
            ->where('whatsapp_account_id', $account->getKey())
            ->where('contact_phone', $phone)
            ->open()
            ->orderByDesc('id')
            ->first();

        if ($existing instanceof Conversation) {
            return $existing;
        }

        /** @var Conversation $conversation */
        $conversation = Conversation::query()->create([
            'channel' => ConversationChannel::WhatsApp,
            'whatsapp_account_id' => $account->getKey(),
            'customer_id' => $booking->customerId,
            'branch_id' => $booking->branchId,
            'contact_phone' => $phone,
            'status' => ConversationStatus::AiActive,
            'locale' => $locale,
        ]);

        return $conversation;
    }

    private function settle(
        WhatsAppOutboundNotice $notice,
        NoticeStatus $status,
        ?string $reason,
        string $locale,
        bool $attempted = true,
        ?Conversation $conversation = null,
        ?int $messageId = null,
    ): WhatsAppOutboundNotice {
        $now = CarbonImmutable::now()->utc();

        $values = [
            'status' => $status,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 64),
            'locale' => $locale,
        ];

        if ($attempted) {
            $values['attempts'] = $notice->attempts + 1;
            $values['last_attempt_at'] = $now;
        }

        if ($conversation instanceof Conversation) {
            $values['conversation_id'] = $conversation->getKey();
        }

        if ($messageId !== null) {
            $values['message_id'] = $messageId;
        }

        $notice->forceFill($values)->save();

        return $notice;
    }
}
