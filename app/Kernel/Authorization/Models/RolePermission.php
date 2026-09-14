<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One permission grant on one role.
 *
 * A row rather than a JSON array so grants can be queried, joined and audited
 * individually — "who can refund" must be answerable with a query.
 *
 * @property int $role_id
 * @property string $permission
 */
final class RolePermission extends Model
{
    use UsesTenantConnection;

    protected $table = 'role_permissions';

    protected $guarded = [];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
