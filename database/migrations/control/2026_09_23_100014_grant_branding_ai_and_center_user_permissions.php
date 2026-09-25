<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Four new platform permissions: platform.branding.manage, platform.ai.manage,
 * platform.center_user.view and platform.center_user.manage. The system Super
 * Admin role holds every permission as explicit rows (there is no bypass), so
 * it gains them here. Custom roles gain nothing: least privilege.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        $role = DB::connection($this->connection)->table('platform_roles')->where('key', 'super_admin')->value('id');
        if ($role === null) {
            return;
        }
        foreach (['platform.branding.manage', 'platform.ai.manage', 'platform.center_user.view', 'platform.center_user.manage'] as $permission) {
            DB::connection($this->connection)->table('platform_role_permissions')->insertOrIgnore([
                'role_id' => $role, 'permission' => $permission, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: removing a permission from the Super Admin role is
        // a decision for PlatformRoleSynchroniser, not a schema rollback.
    }
};
