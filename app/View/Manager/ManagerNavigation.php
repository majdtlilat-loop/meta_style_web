<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * The Manager sidebar, as data.
 *
 * Two questions, kept apart on purpose:
 *
 *   PERMISSION  may this person use the module at all? No → the item is not
 *               shown. Nobody is advertised a feature they could never use.
 *   ENTITLEMENT does the center own it? No (while the subscription is in good
 *               standing) → the item is shown LOCKED, with a label read from
 *               the real plan catalog ("Available from Business" / "Contact
 *               us"), and it opens the upgrade prompt instead of a page.
 *
 * A suspended, cancelled or expired center gets no locks at all — that is one
 * account-wide banner ({@see SubscriptionBanner}), not "your plan lacks
 * everything". An item whose route does not exist yet is simply absent, so
 * other areas' pages appear here the moment their route is registered.
 *
 * Presentation only: every route and Action authorises again on the server.
 */
final class ManagerNavigation
{
    /**
     * key => [route, active route patterns ("|"-separated), icon, group, any-of permissions, any-of features, keeps history]
     *
     * "keeps history" marks a page that stays readable after a downgrade
     * (docs/05-ENTITLEMENTS.md): its upgrade prompt also offers the way in.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string|null, 4: list<Permission>, 5: list<string>, 6: bool}>
     */
    private const ITEMS = [
        'dashboard' => ['center.dashboard', 'center.dashboard', 'dashboard', null, [], [], false],

        'calendar' => ['center.calendar', 'center.calendar*', 'calendar', 'operations', [Permission::AppointmentView, Permission::AppointmentViewOwn], ['booking'], true],
        'board' => ['center.board', 'center.board*', 'journey', 'operations', [Permission::JourneyView, Permission::JourneyViewOwn], [], false],
        'queue' => ['center.queue', 'center.queue*', 'queue', 'operations', [Permission::QueueView], ['queue_management'], false],
        'pos' => ['center.pos', 'center.pos*', 'pos', 'operations', [Permission::SaleCreate, Permission::SaleFinalize], ['pos'], false],

        'customers' => ['center.customers', 'center.customers*', 'customers', 'customers', [Permission::CustomerView], [], false],
        'loyalty' => ['center.loyalty', 'center.loyalty*', 'loyalty', 'customers', [Permission::LoyaltyView], ['loyalty'], true],
        'memberships' => ['center.memberships', 'center.memberships*', 'memberships', 'customers', [Permission::MembershipView], ['memberships'], true],
        'packages' => ['center.packages', 'center.packages*', 'packages', 'customers', [Permission::PackageView], ['packages'], true],
        'reviews' => ['center.reviews', 'center.reviews*', 'reviews', 'customers', [Permission::ReviewView], ['reviews'], false],

        'catalog' => ['center.catalog', 'center.catalog*', 'catalog', 'center', [Permission::ServiceView], [], false],
        'staff' => ['center.staff', 'center.staff*', 'staff', 'center', [Permission::StaffView], [], false],
        'resources' => ['center.resources', 'center.resources*', 'resources', 'center', [Permission::ResourceView], [], false],
        'branches' => ['center.branches', 'center.branches*', 'branches', 'center', [Permission::BranchView], [], false],

        // Cashier shifts is a tab of the same money pages, reached from Sales.
        'sales' => ['center.sales', 'center.sales*|center.shifts', 'sales', 'finance', [Permission::SaleView], ['pos'], true],
        'payments' => ['center.payments', 'center.payments*', 'wallet', 'finance', [Permission::PaymentView], [], false],
        'finance' => ['center.finance', 'center.finance', 'finance', 'finance', [Permission::FinanceView], ['finance'], true],
        'expenses' => ['center.expenses', 'center.expenses*', 'expenses', 'finance', [Permission::ExpenseManage], ['finance'], true],
        'payment_gateways' => ['center.payment_gateways', 'center.payment_gateways*', 'payments', 'finance', [Permission::PaymentGatewayManage], ['payments'], false],

        'reports' => ['center.reports', 'center.reports*', 'reports', 'analytics', [Permission::ReportView], ['reports_standard'], false],
        'advanced_reports' => ['center.advanced-reports', 'center.advanced-reports*', 'advanced-reports', 'analytics', [Permission::ReportView], ['reports_advanced'], false],

        'conversations' => ['center.conversations', 'center.conversations*', 'conversations', 'communication', [Permission::ConversationView], ['whatsapp_booking', 'rayan_ai'], false],
        'support' => ['center.support', 'center.support*', 'support', 'communication', [Permission::PlatformSupportView], [], false],
        'notifications' => ['center.notifications', 'center.notifications*', 'notifications', 'communication', [], [], false],

        'brand' => ['center.appearance.brand', 'center.appearance.brand*', 'palette', 'appearance', [Permission::AppearanceView], [], false],
        'site' => ['center.appearance.site', 'center.appearance.site*', 'globe', 'appearance', [Permission::AppearanceView], [], false],
        'menu' => ['center.menu', 'center.menu*', 'list', 'appearance', [Permission::MenuView], [], false],
        'booking_page' => ['center.appearance.booking', 'center.appearance.booking*', 'smartphone', 'appearance', [Permission::AppearanceView], [], false],
        'cart' => ['center.appearance.cart', 'center.appearance.cart*', 'cart', 'appearance', [Permission::AppearanceView], [], false],
        'print' => ['center.appearance.print', 'center.appearance.print*', 'print', 'appearance', [Permission::AppearanceView], ['printing'], false],

        'settings' => ['center.settings', 'center.settings*', 'settings', 'settings', [Permission::SettingsView], [], false],
        // Status is readable with settings.view; configuring needs whatsapp.manage (checked in the Action).
        'whatsapp' => ['center.integrations.whatsapp', 'center.integrations.whatsapp*', 'message', 'settings', [Permission::SettingsView, Permission::WhatsAppManage], ['whatsapp_booking'], false],
        'roles' => ['center.roles', 'center.roles*', 'roles', 'settings', [Permission::RoleView], [], false],
        'plan' => ['center.plan', 'center.plan*', 'plans', 'settings', [Permission::SettingsView], [], false],
        'usage' => ['center.usage', 'center.usage*', 'usage', 'settings', [Permission::SettingsView], [], false],
    ];

    /** Sidebar order of the groups; the first (no heading) holds the overview. */
    private const GROUPS = [null, 'operations', 'customers', 'center', 'finance', 'analytics', 'communication', 'appearance', 'settings'];

    public function __construct(private readonly FeatureOffer $offers) {}

    /**
     * The sidebar for one person, plus the topbar trail.
     *
     * @return array{
     *     groups: list<array{label: string|null, items: list<array<string, mixed>>}>,
     *     trail_group: string|null,
     *     trail_page: string|null
     * }
     */
    public function build(User $user, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $byGroup = array_fill_keys(array_map(static fn (?string $group): string => (string) $group, self::GROUPS), []);

        foreach (self::ITEMS as $key => [$route, $active, $icon, $group, $permissions, $features]) {
            if (! Route::has($route) || ! $this->permitted($user, $permissions)) {
                continue;
            }

            $item = [
                'key' => $key,
                'href' => route($route),
                'icon' => $icon,
                'label' => __('ui.manager_nav.items.'.$key),
                'active' => request()->routeIs(...explode('|', $active)),
                'badge' => null,
                'count' => null,
                'locked' => false,
            ];

            $locked = $this->lockedFeature($features);
            if ($locked !== null) {
                $label = (string) $this->offers->lockLabel($locked, $locale);
                $item = [
                    ...$item,
                    // The no-JavaScript fallback: the plan page, the feature highlighted.
                    'href' => Route::has('center.plan') ? route('center.plan', ['feature' => $locked]) : $item['href'],
                    'locked' => true,
                    'feature' => $locked,
                    'lock_label' => $label,
                    'tooltip' => $item['label'].' · '.$label,
                    'aria_label' => __('manager_shell.nav.locked_item', ['item' => $item['label'], 'label' => $label]),
                ];
            }

            $byGroup[(string) $group][] = $item;
        }

        $groups = [];
        $trailGroup = null;
        $trailPage = null;

        foreach (self::GROUPS as $group) {
            $items = $byGroup[(string) $group];
            if ($items === []) {
                continue;
            }

            $label = $group === null ? null : __('ui.manager_nav.groups.'.$group);
            $groups[] = ['label' => $label, 'items' => $items];

            foreach ($items as $item) {
                if ($item['active']) {
                    // "Customers › Customers" says nothing twice.
                    $trailGroup = $label === $item['label'] ? null : $label;
                    $trailPage = $item['label'];
                }
            }
        }

        return ['groups' => $groups, 'trail_group' => $trailGroup, 'trail_page' => $trailPage];
    }

    /**
     * The features whose upgrade prompt this person may open: those of the
     * items they hold a permission for. Anything else is refused, so a
     * crafted event can neither advertise a module to someone who could never
     * use it nor turn an arbitrary string into a translation lookup.
     *
     * @return list<string>
     */
    public function offerableFeatures(User $user): array
    {
        $features = [];
        foreach (self::ITEMS as [$route, , , , $permissions, $itemFeatures]) {
            if ($itemFeatures !== [] && Route::has($route) && $this->permitted($user, $permissions)) {
                $features = [...$features, ...$itemFeatures];
            }
        }

        return array_values(array_unique($features));
    }

    /**
     * Where the prompt may also offer the page itself, for a feature whose
     * pages keep their history readable after a downgrade.
     */
    public function historyHref(User $user, string $feature): ?string
    {
        foreach (self::ITEMS as [$route, , , , $permissions, $features, $history]) {
            if ($history && in_array($feature, $features, true) && Route::has($route) && $this->permitted($user, $permissions)) {
                return route($route);
            }
        }

        return null;
    }

    /**
     * Every feature key the Manager navigation knows about.
     *
     * @return list<string>
     */
    public static function features(): array
    {
        $features = [];
        foreach (self::ITEMS as [, , , , , $itemFeatures]) {
            $features = [...$features, ...$itemFeatures];
        }

        return array_values(array_unique($features));
    }

    /** @param list<Permission> $permissions */
    private function permitted(User $user, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The feature to offer when the center owns NONE of an item's features
     * and a lock should show; null when the item is usable (or unlocked by
     * the subscription state, which the banner explains instead).
     *
     * @param  list<string>  $features
     */
    private function lockedFeature(array $features): ?string
    {
        if ($features === []) {
            return null;
        }

        foreach ($features as $feature) {
            if (! $this->offers->isLocked($feature)) {
                return null;
            }
        }

        // Of several (conversations: WhatsApp OR RAYAN), offer the one a
        // public plan actually sells.
        foreach ($features as $feature) {
            if ($this->offers->state($feature) === 'upgrade') {
                return $feature;
            }
        }

        return $features[0];
    }
}
