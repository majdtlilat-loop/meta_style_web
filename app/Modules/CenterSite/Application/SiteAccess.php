<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may see and change the center's public site and brand.
 *
 * `appearance.view` opens the builder and the draft preview; `appearance.manage`
 * saves, publishes, restores and uploads. Uploading additionally needs
 * `media.upload`, which StoreMediaItem checks itself. Checked in every Action —
 * a hidden button is presentation, not authorisation.
 */
final class SiteAccess
{
    public static function canView(User $user): bool
    {
        return $user->hasPermission(Permission::AppearanceView) || $user->hasPermission(Permission::AppearanceManage);
    }

    public static function canManage(User $user): bool
    {
        return $user->hasPermission(Permission::AppearanceManage);
    }

    /**
     * @throws AuthorizationException
     */
    public static function ensureView(User $user): void
    {
        if (! self::canView($user)) {
            throw new AuthorizationException(__('manager_site.errors.forbidden'));
        }
    }

    /**
     * @throws AuthorizationException
     */
    public static function ensureManage(User $user): void
    {
        if (! self::canManage($user)) {
            throw new AuthorizationException(__('manager_site.errors.forbidden'));
        }
    }
}
