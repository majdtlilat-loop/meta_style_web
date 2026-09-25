<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Models;

use App\Kernel\Platform\Authorization\PlatformPermission;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property string $password
 * @property int $id
 * @property bool $is_active
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property string|null $mfa_secret
 * @property list<string>|null $mfa_recovery_codes
 * @property Carbon|null $mfa_confirmed_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 */
final class PlatformUser extends Authenticatable
{
    protected $connection = 'control';

    protected $table = 'platform_users';

    protected $guarded = [];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $user): void {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /** @return BelongsToMany<PlatformRole, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            PlatformRole::class,
            'platform_user_roles',
            'user_id',
            'role_id',
        )->withTimestamps();
    }

    /** @return list<string> */
    public function permissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        /** @var list<string> $permissions */
        $permissions = DB::connection('control')
            ->table('platform_role_permissions')
            ->join('platform_user_roles', 'platform_user_roles.role_id', '=', 'platform_role_permissions.role_id')
            ->where('platform_user_roles.user_id', $this->getKey())
            ->distinct()->pluck('platform_role_permissions.permission')->all();

        return $this->permissionCache = $permissions;
    }

    public function hasPermission(PlatformPermission|string $permission): bool
    {
        if (! $this->is_active || $this->archived_at !== null) {
            return false;
        }

        $code = $permission instanceof PlatformPermission ? $permission->value : $permission;

        // No owner/super-admin bypass: every grant is an explicit stored row.
        return in_array($code, $this->permissions(), true);
    }

    public function hasConfirmedMfa(): bool
    {
        return $this->mfa_confirmed_at !== null && is_string($this->mfa_secret) && $this->mfa_secret !== '';
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }
}
