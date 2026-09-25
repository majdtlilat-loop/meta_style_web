<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two new platform permissions: platform.security.manage and
 * platform.announcement.send. The system Super Admin role holds every
 * permission as explicit rows (there is no bypass), so an existing role gains
 * them here. Two idempotent rows, not a backfill.
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
        foreach (['platform.security.manage', 'platform.announcement.send'] as $permission) {
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
