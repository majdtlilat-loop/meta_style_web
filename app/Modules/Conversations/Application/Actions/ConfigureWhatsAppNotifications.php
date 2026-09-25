<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Application\WhatsAppNotificationSettings;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Switches the guest booking confirmation on or off (docs/25-WHATSAPP.md §22).
 *
 * The same gates as the connection itself: the center must own
 * `whatsapp_booking` (sending in the center's name is new activity) and the
 * person must hold `whatsapp.manage` — the permission that already decides who
 * may make this center's number speak. Checked here, whatever the screen
 * showed; hiding the switch is presentation.
 *
 * Audited as `settings.whatsapp_notifications.updated` with the booleans
 * before and after — configuration, never a customer detail.
 */
final class ConfigureWhatsAppNotifications
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly WhatsAppNotificationSettings $settings,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array{guest_booking_confirmation: bool} the settings now in force
     *
     * @throws EntitlementRequired
     * @throws AuthorizationException
     */
    public function guestBookingConfirmation(User $user, bool $enabled): array
    {
        $this->entitlements->ensure('whatsapp_booking');

        if (! $user->hasPermission(Permission::WhatsAppManage)) {
            throw new AuthorizationException('You may not change WhatsApp notices.');
        }

        $before = $this->settings->snapshot();

        if ($before['guest_booking_confirmation'] === $enabled) {
            return $before;
        }

        $this->settings->saveGuestBookingConfirmation($enabled);
        $after = $this->settings->snapshot();

        $this->audit->record(new AuditEvent(
            action: 'settings.whatsapp_notifications.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($user),
            targetType: WhatsAppNotificationSettings::class,
            targetId: 'whatsapp_notifications',
            before: $before,
            after: $after,
        ));

        return $after;
    }
}
