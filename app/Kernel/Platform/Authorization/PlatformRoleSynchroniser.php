<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Authorization;

use App\Kernel\Platform\Identity\Models\PlatformRole;
use Illuminate\Support\Facades\DB;

final class PlatformRoleSynchroniser
{
    public const SUPER_ADMIN = 'super_admin';

    public function sync(): PlatformRole
    {
        /** @var PlatformRole $role */
        $role = PlatformRole::query()->updateOrCreate(
            ['key' => self::SUPER_ADMIN],
            [
                'name' => ['en' => 'Super Admin', 'ar' => 'المشرف العام', 'ckb' => 'بەڕێوەبەری باڵا'],
                'is_system' => true,
            ],
        );

        DB::connection('control')->transaction(function () use ($role): void {
            DB::connection('control')->table('platform_role_permissions')->where('role_id', $role->id)->delete();

            DB::connection('control')->table('platform_role_permissions')->insert(array_map(
                static fn (string $permission): array => [
                    'role_id' => $role->id,
                    'permission' => $permission,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                PlatformPermission::codes(),
            ));
        });

        return $role;
    }
}
