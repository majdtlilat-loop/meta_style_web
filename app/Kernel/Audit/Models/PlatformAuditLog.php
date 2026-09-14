<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Models;

use App\Kernel\Audit\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * Audit entries for platform-side actions, including anything done with no
 * tenant bound (docs/08-AUDIT-SECURITY.md §2).
 *
 * @property string $uuid
 * @property string $action
 * @property string|null $tenant_id
 */
final class PlatformAuditLog extends Model
{
    use AppendOnly;

    public $timestamps = false;

    protected $connection = 'control';

    protected $table = 'platform_audit_logs';

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
