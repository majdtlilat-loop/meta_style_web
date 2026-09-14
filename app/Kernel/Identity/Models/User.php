<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Models;

use App\Kernel\Authorization\BranchScope;
use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * A staff login, in the tenant's own database.
 *
 * Identity and authorization only. Business facts about the person — what they
 * do for customers, which services they perform, their schedule — belong to
 * the Employee record, which a User may or may not have. The link is declared
 * on Employee, not here: Identity is a Kernel service and must not depend on a
 * business module (docs/04-MODULE-BOUNDARIES.md §2).
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password
 * @property bool $is_active
 * @property bool $is_owner
 * @property bool $all_branches
 */
final class User extends Authenticatable
{
    use HasApiTokens;
    use UsesTenantConnection;

    protected $table = 'users';

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /**
     * Resolved once per request. Permissions are read on nearly every
     * authorization check, and re-querying two tables each time turns a policy
     * call into a database round trip.
     *
     * @var list<string>|null
     */
    private ?array $permissionCache = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_owner' => 'boolean',
            'all_branches' => 'boolean',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $user): void {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles')->withTimestamps();
    }

    /**
     * Every permission this user holds, through their roles.
     *
     * There is no owner short-circuit. The Owner role carries explicit grants
     * for the whole catalog, so an owner reaches `true` the same way everyone
     * else does — which keeps the answer auditable, and lets a center narrow it
     * if it ever wants to (docs/DECISIONS.md ADR-029).
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        /** @var list<string> $codes */
        $codes = DB::connection($this->getConnectionName())
            ->table('role_permissions')
            ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->where('user_roles.user_id', $this->getKey())
            ->distinct()
            ->pluck('role_permissions.permission')
            ->all();

        return $this->permissionCache = $codes;
    }

    public function hasPermission(Permission|string $permission): bool
    {
        // An inactive account holds no permissions at all. Enforcing this here
        // rather than only at login means a session or token that outlives a
        // deactivation stops working on the next request.
        if (! $this->is_active) {
            return false;
        }

        $code = $permission instanceof Permission ? $permission->value : $permission;

        return in_array($code, $this->permissions(), true);
    }

    /**
     * Branch scope — permission alone is not authorization.
     *
     * A manager with `staff.update` scoped to Branch 1 must not edit staff in
     * Branch 2. Inside a tenant this is the most likely place for a leak, so
     * policies check both (docs/06-AUTH-ROLES-PERMISSIONS.md §5).
     */
    public function branchScope(): BranchScope
    {
        if (! $this->is_active) {
            return BranchScope::none();
        }

        if ($this->all_branches) {
            return BranchScope::all();
        }

        /** @var list<int> $ids */
        $ids = DB::connection($this->getConnectionName())
            ->table('user_branches')
            ->where('user_id', $this->getKey())
            ->pluck('branch_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return BranchScope::limitedTo($ids);
    }

    public function canAccessBranch(int $branchId): bool
    {
        return $this->branchScope()->allows($branchId);
    }

    /**
     * Replaces this user's branch scope.
     *
     * Writes the pivot directly rather than through a relation to a Branch
     * model: Identity is a Kernel service and Branches is a business module, so
     * the dependency would run the wrong way (docs/04-MODULE-BOUNDARIES.md §2).
     * Branch ids are an authorization concept; Branch rows are not.
     *
     * @param  list<int>  $branchIds
     */
    public function syncBranchScope(array $branchIds): void
    {
        $connection = DB::connection($this->getConnectionName());

        $connection->table('user_branches')->where('user_id', $this->getKey())->delete();

        foreach (array_unique($branchIds) as $branchId) {
            $connection->table('user_branches')->insert([
                'user_id' => $this->getKey(),
                'branch_id' => $branchId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function hasRole(string $key): bool
    {
        return $this->roles()->where('key', $key)->exists();
    }

    /**
     * Call after changing role assignments within a single request.
     */
    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
    }

    /**
     * A user with no password has been created but never activated — they
     * exist, they just cannot sign in yet.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active && $this->password !== null;
    }
}
