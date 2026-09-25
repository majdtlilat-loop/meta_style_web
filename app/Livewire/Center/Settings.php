<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Manager → Settings: only what is genuinely center-level.
 *
 * Five sections, each its own child component with its own server-side
 * checks — General (the center's profile), Languages (content languages),
 * Booking (the booking rules), Policies (the texts guests read) and
 * Notifications (the signed-in person's own preferences) — plus links to the
 * pages that have their own home (appearance, branches, roles, plan,
 * payments). No platform, system or provider secret is ever read here.
 *
 * `settings.view` opens the page; every save checks `settings.manage` in its
 * Action.
 */
#[Layout('components.layouts.app')]
final class Settings extends Component
{
    public const TABS = ['general', 'languages', 'booking', 'policies', 'notifications'];

    #[Url(as: 'tab', except: 'general')]
    public string $tab = 'general';

    public function mount(): void
    {
        $this->viewer();

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'general';
        }
    }

    public function showTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function render(Entitlements $entitlements): View
    {
        $user = $this->viewer();
        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'general';

        return view('livewire.center.settings', [
            'current' => $tab,
            'tabs' => [
                ['key' => 'general', 'icon' => 'building', 'label' => __('manager_settings.nav.general')],
                ['key' => 'languages', 'icon' => 'languages', 'label' => __('manager_settings.nav.languages')],
                ['key' => 'booking', 'icon' => 'calendar', 'label' => __('manager_settings.nav.booking'), 'locked' => ! $entitlements->enabled('booking')],
                ['key' => 'policies', 'icon' => 'file-text', 'label' => __('manager_settings.nav.policies')],
                ['key' => 'notifications', 'icon' => 'bell', 'label' => __('manager_settings.nav.notifications')],
            ],
            'links' => $this->links($user, $entitlements),
        ])->title(__('manager_settings.title'));
    }

    /**
     * The pages that own their own settings, for the people who may open them.
     *
     * @return list<array{href: string, icon: string, label: string, badge: string|null}>
     */
    private function links(User $user, Entitlements $entitlements): array
    {
        $appearance = match (true) {
            Route::has('center.appearance.brand') && $user->hasPermission(Permission::AppearanceView) => 'center.appearance.brand',
            $user->hasPermission(Permission::MenuView) => 'center.menu',
            $user->hasPermission(Permission::AppearanceView) => 'center.appearance.booking',
            default => null,
        };

        $candidates = [
            ['appearance', $appearance, 'appearance', true, null],
            ['branches', 'center.branches', 'branches', $user->hasPermission(Permission::BranchView), null],
            ['roles', 'center.roles', 'roles', $user->hasPermission(Permission::RoleView), null],
            ['plan', 'center.plan', 'plans', $user->hasPermission(Permission::SettingsView), null],
            ['payments', 'center.payment_gateways', 'payments', $user->hasPermission(Permission::PaymentGatewayManage), $entitlements->enabled('payments') ? null : __('manager_settings.links.locked')],
            ['usage', 'center.usage', 'usage', $user->hasPermission(Permission::SettingsView), null],
        ];

        $links = [];

        foreach ($candidates as [$key, $route, $icon, $allowed, $badge]) {
            if ($allowed && is_string($route) && Route::has($route)) {
                $links[] = ['href' => route($route), 'icon' => $icon, 'label' => __('manager_settings.nav.'.$key), 'badge' => $badge];
            }
        }

        return $links;
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission(Permission::SettingsView), 403);

        return $user;
    }
}
