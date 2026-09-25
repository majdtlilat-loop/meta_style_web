<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Directory;

use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One center user account as the platform last projected it. A read model:
 * written only by CenterUserDirectory, never used to authorize anything.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $user_uuid
 * @property string $name
 * @property string|null $email
 * @property string|null $phone_e164
 * @property string|null $phone_country
 * @property string|null $phone_national
 * @property string $kind
 * @property string|null $role_key
 * @property list<array{key: string, name: array<string, string>|string}>|null $roles
 * @property bool $is_owner
 * @property bool $is_active
 * @property bool $all_branches
 * @property Carbon|null $account_created_at
 * @property Carbon|null $last_login_at
 * @property Carbon $projected_at
 */
final class CenterUserEntry extends Model
{
    protected $connection = 'control';

    protected $table = 'center_user_directory';

    protected $guarded = [];

    /** @return BelongsTo<TenantModel, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id');
    }

    /** @return HasMany<CenterUserBranch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(CenterUserBranch::class, 'entry_id');
    }

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'is_owner' => 'boolean',
            'is_active' => 'boolean',
            'all_branches' => 'boolean',
            'account_created_at' => 'datetime',
            'last_login_at' => 'datetime',
            'projected_at' => 'datetime',
        ];
    }
}
