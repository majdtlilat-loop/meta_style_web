<?php

declare(strict_types=1);

namespace App\Livewire\Center\Integrations;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Modules\Conversations\Application\Actions\ConfigureWhatsAppNotifications;
use App\Modules\Conversations\Application\GuestConfirmationReadiness;
use App\Modules\Conversations\Application\WhatsAppNotificationSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Settings → WhatsApp → "Send booking confirmation to guest customers via
 * WhatsApp" (docs/25-WHATSAPP.md §22).
 *
 * The switch is the center's choice; the status beside it is the TRUTH, read
 * from the same {@see GuestConfirmationReadiness} the sender asks — so the
 * card says "Not sending" and why (not connected, turned off, no approved
 * template, provider unavailable, no language it can be sent in) instead of a
 * switch that pretends — and which of the center's languages (EN / AR / KU)
 * a confirmation can actually be written in.
 *
 * Changing it goes through `ConfigureWhatsAppNotifications` (`whatsapp_booking`
 * + `whatsapp.manage`, audited). Everybody who can open the page sees the
 * state and the last 30 days; nobody else can change it, whatever the browser
 * sends.
 */
final class BookingConfirmations extends Component
{
    public bool $enabled = true;

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(WhatsAppNotificationSettings $settings): void
    {
        $this->viewer();
        $this->enabled = $settings->guestBookingConfirmation();
    }

    /**
     * The switch, flipped. Saved through the Action; refused changes snap
     * back to what is stored.
     */
    public function updatedEnabled(ConfigureWhatsAppNotifications $configure, WhatsAppNotificationSettings $settings): void
    {
        $this->notice = '';

        try {
            $after = $configure->guestBookingConfirmation($this->viewer(), $this->enabled);
        } catch (EntitlementRequired) {
            $this->refuse($settings, 'manager_whatsapp.errors.entitlement');

            return;
        } catch (AuthorizationException) {
            $this->refuse($settings, 'manager_whatsapp.errors.forbidden');

            return;
        }

        $this->enabled = $after['guest_booking_confirmation'];
        $this->notice = (string) __($this->enabled ? 'manager_whatsapp.confirmations.saved_on' : 'manager_whatsapp.confirmations.saved_off');
        $this->noticeTone = 'success';
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(
        Entitlements $entitlements,
        GuestConfirmationReadiness $readiness,
        WhatsAppStatusView $present,
        LanguageRegistry $languages,
    ): View {
        $user = $this->viewer();
        $owned = $entitlements->enabled('whatsapp_booking');
        $account = $readiness->account();
        $blocker = $owned ? $readiness->channelBlocker($account) : 'channel_inactive';
        $summary = $owned ? $readiness->summary() : null;
        $issue = $summary['last_issue'] ?? null;

        $state = match (true) {
            ! $this->enabled => 'off',
            $blocker !== null => 'blocked',
            default => 'active',
        };

        // The languages a confirmation can really be written in: the center's
        // enabled ones the platform mapped to a Meta template language.
        $templateLanguages = $readiness->templateLanguages();
        $fallback = $readiness->fallbackLanguage();

        return view('livewire.center.integrations.booking-confirmations', [
            'owned' => $owned,
            'canManage' => $owned && $user->hasPermission(Permission::WhatsAppManage),
            'state' => $state,
            'stateLabel' => __('manager_whatsapp.confirmations.states.'.$state),
            'stateTone' => ['active' => 'success', 'blocked' => 'warning', 'off' => 'neutral'][$state],
            'blocker' => $blocker === null ? null : __('manager_whatsapp.confirmations.blockers.'.$blocker),
            'template' => $readiness->template(),
            'language' => $fallback === null ? null : __('manager_whatsapp.confirmations.language_value', [
                'primary' => $languages->shortLabel($fallback['locale']),
            ]),
            'languages' => array_map(static fn (string $locale, ?string $code): array => [
                'locale' => $locale,
                // EN / AR / KU — never the code (Kurdish is `ckb` internally).
                'label' => $languages->shortLabel($locale),
                'sendable' => $code !== null,
            ], array_keys($templateLanguages), array_values($templateLanguages)),
            'counts' => $summary === null ? null : array_map(static fn (string $key, int $count): array => [
                'key' => $key,
                'label' => __('manager_whatsapp.confirmations.counts.'.$key),
                'count' => $count,
            ], array_keys($summary['counts']), array_values($summary['counts'])),
            'total' => $summary === null ? 0 : array_sum($summary['counts']),
            'issue' => $issue === null ? null : [
                'label' => __('manager_whatsapp.confirmations.issue_states.'.$issue['state']),
                'reason' => $this->reasonLabel($issue['reason']),
                'time' => $present->time($issue['at']),
            ],
            'days' => GuestConfirmationReadiness::SUMMARY_DAYS,
        ]);
    }

    /**
     * A skip reason is translated; a provider's own failure code is shown as
     * the code it is (safe, never the provider's message).
     *
     * @return array{text: string, code: bool}|null
     */
    private function reasonLabel(?string $reason): ?array
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        $key = 'manager_whatsapp.confirmations.reasons.'.$reason;
        $label = __($key);

        return $label === $key
            ? ['text' => $reason, 'code' => true]
            : ['text' => (string) $label, 'code' => false];
    }

    private function refuse(WhatsAppNotificationSettings $settings, string $key): void
    {
        $this->enabled = $settings->guestBookingConfirmation();
        $this->notice = (string) __($key);
        $this->noticeTone = 'danger';
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless(
            $user instanceof User
            && ($user->hasPermission(Permission::SettingsView) || $user->hasPermission(Permission::WhatsAppManage)),
            403,
        );

        return $user;
    }
}
