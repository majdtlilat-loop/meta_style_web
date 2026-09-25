<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity;

use App\Kernel\Platform\Identity\Models\PlatformUser;

/**
 * Where a platform user lands after signing in.
 *
 * The dashboard needs its own permission, so a narrowly scoped role — "support
 * tickets only" — must land on the first screen it may actually open instead
 * of on a 403. Order follows the navigation.
 */
final class PlatformLanding
{
    /** @var array<string, string> route => permission */
    public const ROUTES = [
        'superadmin.dashboard' => 'platform.dashboard.view',
        'superadmin.centers.index' => 'platform.center.view',
        'superadmin.subscriptions.index' => 'platform.subscription.manage',
        'superadmin.billing.index' => 'platform.billing.manage',
        'superadmin.plans.index' => 'platform.plan.manage',
        'superadmin.support.index' => 'platform.support.view',
        'superadmin.operations.index' => 'platform.operations.view',
        'superadmin.audit.index' => 'platform.audit.view',
        'superadmin.cms.index' => 'platform.cms.manage',
        'superadmin.users.index' => 'platform.user.manage',
        'superadmin.settings.index' => 'platform.settings.manage',
    ];

    public static function routeFor(mixed $user): string
    {
        if ($user instanceof PlatformUser) {
            foreach (self::ROUTES as $route => $permission) {
                if ($user->hasPermission($permission)) {
                    return $route;
                }
            }
        }

        return 'superadmin.account';
    }
}
