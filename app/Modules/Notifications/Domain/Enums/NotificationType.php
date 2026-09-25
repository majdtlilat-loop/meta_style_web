<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Enums;

/**
 * Every notification Meta Style can produce, and who it is for.
 *
 * SIXTEEN, and each one had to answer the same question before it was added:
 * who receives this, and what do they DO about it? A notification nobody acts
 * on is noise, and an inbox full of noise is an inbox nobody reads — at which
 * point the one that mattered is lost too (docs/23-NOTIFICATIONS.md §3).
 *
 * So there is no `appointment_created` (the person who booked it was there),
 * no `payment_succeeded` (they just paid), no `loyalty_points_earned` (every
 * bill would produce one) and no `journey_completed` (they walked out).
 *
 * The audience is a property of the TYPE, not of the call site: `audience()` is
 * what stops a staff-only alert from ever being addressed to a customer.
 */
enum NotificationType: string
{
    // ---- The customer's own visits -----------------------------------
    case AppointmentConfirmed = 'appointment_confirmed';
    case AppointmentRescheduled = 'appointment_rescheduled';
    case AppointmentCancelled = 'appointment_cancelled';
    case AppointmentReminder = 'appointment_reminder';

    // ---- Their money and their benefits ------------------------------
    case InvoiceIssued = 'invoice_issued';
    case MembershipActivated = 'membership_activated';
    case PackageActivated = 'package_activated';
    case MembershipExpiring = 'membership_expiring';
    case PackageExpiring = 'package_expiring';

    // ---- Feedback -----------------------------------------------------
    case ReviewInvitationReady = 'review_invitation_ready';

    /** The first staff-facing type, and Phase 12's only `important` one. */
    case LowRatingReceived = 'low_rating_received';

    /*
     * ---- Phase 13: the WhatsApp channel and the assistant ------------
     *
     * ALL STAFF-FACING, and every one of them answers the same question a
     * notification has to answer before it is allowed to exist: who receives
     * this, and what do they DO about it (docs/23-NOTIFICATIONS.md §3)?
     *
     *   takeover requested  go and answer the customer who asked for a person
     *   unanswered          somebody has been waiting too long; pick it up
     *   provider failed     the WhatsApp connection is refusing messages; a
     *                       manager has to look at the credentials
     *   quota warning       the AI allowance is running down; decide before it
     *                       runs out
     *   quota exhausted     it has run out; conversations are going to people
     *                       now
     *
     * Deliberately absent: anything per-message. A notification for every
     * inbound WhatsApp message would be an inbox nobody reads, which loses the
     * five above with it.
     */
    case ConversationTakeoverRequested = 'conversation_takeover_requested';
    case ConversationUnanswered = 'conversation_unanswered';
    case WhatsAppProviderFailed = 'whatsapp_provider_failed';
    case AiQuotaWarning = 'ai_quota_warning';
    case AiQuotaExhausted = 'ai_quota_exhausted';

    /*
     * ---- Phase 15: Meta Style speaking to the center -------------------
     *
     * A message the Super Admin sends to centers (docs/23 §6: a platform-wide
     * announcement belongs to Super Admin). Staff-facing, addressed by
     * permission. `platform_notice` is the important variant. The words are
     * the platform's, stored once in the control plane and rendered in the
     * reader's language at read time — never copied into a tenant row.
     */
    case PlatformAnnouncement = 'platform_announcement';
    case PlatformNotice = 'platform_notice';

    public function audience(): RecipientKind
    {
        return match ($this) {
            self::LowRatingReceived,
            self::ConversationTakeoverRequested,
            self::ConversationUnanswered,
            self::WhatsAppProviderFailed,
            self::AiQuotaWarning,
            self::AiQuotaExhausted,
            self::PlatformAnnouncement,
            self::PlatformNotice => RecipientKind::Staff,
            default => RecipientKind::Customer,
        };
    }

    public function severity(): NotificationSeverity
    {
        return match ($this) {
            /*
             * A customer waiting for a person, a channel that has stopped
             * working, and an allowance that has run out are all things a
             * center is losing business over while nobody looks.
             *
             * `ai_quota_warning` and `conversation_unanswered` are NORMAL: one
             * is a heads-up with room to act, and the other is already visible
             * in the inbox — marking either important would dilute the three
             * above (docs/23-NOTIFICATIONS.md §4).
             */
            self::LowRatingReceived,
            self::ConversationTakeoverRequested,
            self::WhatsAppProviderFailed,
            self::AiQuotaExhausted,
            self::PlatformNotice => NotificationSeverity::Important,
            default => NotificationSeverity::Normal,
        };
    }

    /**
     * The switch that can suppress this one, or null when it cannot be turned
     * off.
     *
     * A cancellation, a reschedule, a confirmation, an issued invoice and a
     * one-star review are operational and financial facts: somebody has to be
     * told, and a preference that could hide them would make the inbox an
     * unreliable record of what the center did (§8).
     */
    public function preference(): ?PreferenceKey
    {
        return match ($this) {
            self::AppointmentReminder => PreferenceKey::AppointmentReminders,
            self::MembershipExpiring, self::PackageExpiring => PreferenceKey::BenefitExpiry,
            self::ReviewInvitationReady => PreferenceKey::ReviewInvitations,
            default => null,
        };
    }

    /**
     * The translation key its text is rendered from at READ time. Nothing
     * renders at write time: a customer who changes their language sees their
     * whole inbox in it (§7).
     */
    public function isFromPlatform(): bool
    {
        return $this === self::PlatformAnnouncement || $this === self::PlatformNotice;
    }

    public function messageKey(): string
    {
        return 'notifications_inbox.type_'.$this->value;
    }
}
