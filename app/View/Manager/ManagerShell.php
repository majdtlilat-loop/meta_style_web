<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\SaaS\CurrentSubscription;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Support\Facades\Route;

/**
 * Everything the Manager layout shows around a page, computed once in PHP.
 *
 * The layout reads this array and nothing else: no permission checks, no
 * queries and no contact columns in Blade (the account menu gets the signed-in
 * person's OWN address as `contact_email`, CustomerPrivacyTest). Guests — the
 * sign-in and password pages — get only the language and the center name.
 */
final class ManagerShell
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly LanguageRegistry $languages,
        private readonly ManagerNavigation $navigation,
        private readonly CurrentSubscription $subscription,
        private readonly SubscriptionBanner $banner,
        private readonly ViewerTimezone $timezones,
    ) {}

    /**
     * @return array{
     *     locale: string,
     *     dir: string,
     *     center_name: string|null,
     *     signed_in: bool,
     *     home: string|null,
     *     logout: string|null,
     *     groups: list<array{label: string|null, items: list<array<string, mixed>>}>,
     *     trail_group: string|null,
     *     trail_page: string|null,
     *     account: array{name: string, initials: string, contact_email: string|null, role_label: string|null, links: list<array{href: string, icon: string, label: string}>}|null,
     *     banner: array<string, mixed>|null
     * }
     */
    public function build(): array
    {
        $locale = app()->getLocale();
        $name = $this->tenants->tenant()?->name;
        $user = auth('web')->user();

        $shell = [
            'locale' => $locale,
            'dir' => $this->languages->direction($locale),
            'center_name' => $name !== null && $name !== '' ? $name : null,
            'signed_in' => false,
            'home' => null,
            'logout' => null,
            'groups' => [],
            'trail_group' => null,
            'trail_page' => null,
            'account' => null,
            'banner' => null,
        ];

        if (! $user instanceof User || ! $this->tenants->isBound()) {
            return $shell;
        }

        $navigation = $this->navigation->build($user, $locale);

        return [
            ...$shell,
            'signed_in' => true,
            'home' => Route::has('center.dashboard') ? route('center.dashboard') : null,
            'logout' => Route::has('logout') ? route('logout') : null,
            'groups' => $navigation['groups'],
            'trail_group' => $navigation['trail_group'],
            'trail_page' => $navigation['trail_page'],
            'account' => [
                'name' => (string) $user->name,
                'initials' => PersonInitials::of((string) $user->name),
                'contact_email' => is_string($user->email) && $user->email !== '' ? $user->email : null,
                'role_label' => $this->roleLabel($user, $locale),
                'links' => $this->accountLinks($user),
            ],
            'banner' => $this->banner->for($this->subscription->summary(), $user, $locale, $this->timezones->for($user)),
        ];
    }

    private function roleLabel(User $user, string $locale): ?string
    {
        $names = $user->roles()->orderBy('roles.id')->get()
            ->map(static fn (Role $role): string => (string) $role->name->get($locale))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();

        return $names === [] ? null : implode(' · ', $names);
    }

    /** @return list<array{href: string, icon: string, label: string}> */
    private function accountLinks(User $user): array
    {
        $links = [
            [Permission::SettingsView, 'center.settings', 'settings', __('ui.manager_nav.items.settings')],
            [Permission::SettingsView, 'center.plan', 'plans', __('ui.manager_nav.items.plan')],
            [null, 'center.notifications', 'bell', __('ui.manager_nav.items.notifications')],
            [Permission::PlatformSupportView, 'center.support', 'support', __('ui.manager_nav.items.support')],
        ];

        $allowed = [];
        foreach ($links as [$permission, $route, $icon, $label]) {
            if (Route::has($route) && ($permission === null || $user->hasPermission($permission))) {
                $allowed[] = ['href' => route($route), 'icon' => $icon, 'label' => $label];
            }
        }

        return $allowed;
    }
}
