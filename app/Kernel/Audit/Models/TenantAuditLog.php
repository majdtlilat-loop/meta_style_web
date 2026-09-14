<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Models;

use App\Kernel\Audit\Concerns\AppendOnly;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit entries for actions inside one center, stored in that center's own
 * database so the center can see them (docs/08-AUDIT-SECURITY.md §2).
 *
 * The connection comes from UsesTenantConnection, so a query with no tenant
 * initialised raises TenantConnectionNotInitialized rather than a driver
 * error — the fail-closed rule in docs/02-TENANCY.md §4 at the model layer.
 *
 * @property string $uuid
 * @property string $action
 */
final class TenantAuditLog extends Model
{
    use AppendOnly;
    use UsesTenantConnection;

    public $timestamps = false;

    protected $table = 'audit_logs';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'before' => 'array',
            'after' => 'array',
            'meta' => 'array',
        ];
    }
}
