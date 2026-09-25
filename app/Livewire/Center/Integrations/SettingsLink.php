<?php

declare(strict_types=1);

namespace App\Livewire\Center\Integrations;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Modules\Conversations\Application\WhatsAppReadiness;
use App\Modules\Conversations\Domain\Models\WhatsAppAccount;
use App\View\Manager\FeatureOffer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Settings → "WhatsApp": the link in the Settings navigation, with its state.
 *
 * Locked when the center does not own `whatsapp_booking`: with JavaScript it
 * opens the upgrade prompt (the same one the sidebar uses), without it the page
 * itself, which shows the offer. When a configured account has a problem the
 * link says "Needs attention" — the small alert authorised people see without
 * opening the page. Computed here so the Settings template stays free of logic.
 */
final class SettingsLink extends Component
{
    public function render(Entitlements $entitlements, WhatsAppReadiness $readiness, FeatureOffer $offers): View
    {
        $user = auth()->user();
        $visible = $user instanceof User
            && Route::has('center.integrations.whatsapp')
            && ($user->hasPermission(Permission::SettingsView) || $user->hasPermission(Permission::WhatsAppManage));

        $attention = false;

        if ($visible && $entitlements->enabled('whatsapp_booking')) {
            $account = $readiness->account();
            $result = $account instanceof WhatsAppAccount ? $readiness->check($account) : null;
            // Switched off on purpose is a choice, not an alert.
            $attention = $result !== null && $result['state'] === 'problem' && $result['problem'] !== 'account_disabled';
        }

        return view('livewire.center.integrations.settings-link', [
            'visible' => $visible,
            'href' => $visible ? route('center.integrations.whatsapp') : null,
            'locked' => $visible && $offers->isLocked('whatsapp_booking'),
            'attention' => $attention,
        ]);
    }
}
