<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Enums;

/**
 * The switches a person is allowed to turn off.
 *
 * Deliberately three, and deliberately COARSE. A preference exists for the
 * notifications somebody may reasonably not want — a reminder, an expiry
 * warning, an invitation to review. It does NOT exist for the ones that carry
 * an operational or financial fact the person has to be told about: an
 * appointment somebody else cancelled, an invoice that was issued, a one-star
 * review a manager is responsible for.
 *
 * A preference is about DELIVERY, never about truth. Turning a reminder off
 * does not cancel the appointment, and turning expiry warnings off does not
 * extend a membership (docs/23-NOTIFICATIONS.md §8).
 */
enum PreferenceKey: string
{
    case AppointmentReminders = 'appointment_reminders';
    case BenefitExpiry = 'benefit_expiry';
    case ReviewInvitations = 'review_invitations';

    public function label(): string
    {
        return match ($this) {
            self::AppointmentReminders => __('notifications_inbox.pref_appointment_reminders'),
            self::BenefitExpiry => __('notifications_inbox.pref_benefit_expiry'),
            self::ReviewInvitations => __('notifications_inbox.pref_review_invitations'),
        };
    }
}
