<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Models;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A named set of permissions, owned by one center.
 *
 * @property int $id
 * @property string $uuid
 * @property string $key
 * @property TranslatedText $name
 * @property bool $is_system
 */
final class Role extends Model
{
    use UsesTenantConnection;

    protected $table = 'roles';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $role): void {
            $role->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles')->withTimestamps();
    }

    /**
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        /** @var list<string> $codes */
        $codes = $this->permissions()->pluck('permission')->all();

        return $codes;
    }

    /**
     * Replaces this role's grants.
     *
     * Unknown codes are dropped rather than stored. A permission that no code
     * path checks is worse than absent: it reads as granted in the UI while
     * authorizing nothing.
     *
     * @param  list<Permission|string>  $permissions
     */
    public function syncPermissions(array $permissions): void
    {
        $codes = [];

        foreach ($permissions as $permission) {
            $code = $permission instanceof Permission ? $permission->value : $permission;

            if (Permission::tryFromCode($code) !== null) {
                $codes[$code] = true;
            }
        }

        $wanted = array_keys($codes);
        $existing = $this->permissionCodes();

        $this->permissions()->whereIn('permission', array_diff($existing, $wanted))->delete();

        foreach (array_diff($wanted, $existing) as $code) {
            $this->permissions()->create(['permission' => $code]);
        }
    }

    public function isProtected(): bool
    {
        return $this->is_system;
    }
}
