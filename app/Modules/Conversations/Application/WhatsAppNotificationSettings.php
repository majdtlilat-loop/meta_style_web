<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Which business-initiated WhatsApp notices the center has switched on.
 *
 * One row in the tenant's own `settings` table, beside its languages, booking
 * knobs and RAYAN choices — tenant business configuration, read only inside
 * tenant context (docs/25-WHATSAPP.md §22).
 *
 * ## Absent means ON
 *
 * The Notifications rule (docs/23 §9): a center that never touched the switch
 * gets the confirmation. It still sends nothing until the center has a usable
 * WhatsApp account AND the platform has mapped an approved template for the
 * purpose — both of which are deliberate acts — so "on by default" can never
 * message anybody behind a center's back.
 *
 * ## Channels, per audience
 *
 * Today one switch: guests (no customer account) are confirmed on WhatsApp.
 * Registered customers keep the in-app notification Notifications already
 * sends; this class is where a later per-audience channel choice belongs, so a
 * second opinion about "who hears about a booking, where" never grows
 * elsewhere.
 *
 * Deliberately uncached: it is read once per confirmation and once per page,
 * and a cache here would be one more copy to keep in step with a save made in
 * the same request.
 */
final class WhatsAppNotificationSettings
{
    private const KEY = 'whatsapp_notifications';

    public function __construct(private readonly TenantContext $tenants) {}

    /** Guests are sent a WhatsApp confirmation when their booking is confirmed. */
    public function guestBookingConfirmation(): bool
    {
        return (bool) ($this->all()['guest_booking_confirmation'] ?? true);
    }

    /**
     * @return array{guest_booking_confirmation: bool}
     */
    public function snapshot(): array
    {
        return ['guest_booking_confirmation' => $this->guestBookingConfirmation()];
    }

    public function saveGuestBookingConfirmation(bool $enabled): void
    {
        $current = $this->all();
        $current['guest_booking_confirmation'] = $enabled;

        DB::connection('tenant')->table('settings')->updateOrInsert(
            ['key' => self::KEY],
            [
                'value' => json_encode($current, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        if (! $this->tenants->isBound()) {
            return [];
        }

        $raw = DB::connection('tenant')->table('settings')->where('key', self::KEY)->value('value');
        $stored = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($stored) ? $stored : [];
    }
}
