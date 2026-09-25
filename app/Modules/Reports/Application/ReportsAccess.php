<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ReportsAccess
{
    public function __construct(private Entitlements $entitlements) {}

    public function standard(User $user, Permission $permission = Permission::ReportView): void
    {
        $this->entitlements->ensure('reports_standard');
        $this->permission($user, $permission);
    }

    public function advanced(User $user, Permission $permission = Permission::ReportView): void
    {
        $this->entitlements->ensure('reports_standard');
        $this->entitlements->ensure('reports_advanced');
        $this->permission($user, $permission);
    }

    public function standardEnabled(): bool
    {
        return $this->entitlements->enabled('reports_standard');
    }

    public function advancedEnabled(): bool
    {
        return $this->entitlements->enabled('reports_standard')
            && $this->entitlements->enabled('reports_advanced');
    }

    private function permission(User $user, Permission $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException('You may not access reports.');
        }
    }
}
