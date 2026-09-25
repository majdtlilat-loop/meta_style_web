<?php

declare(strict_types=1);

namespace App\Modules\PlatformOperations\Domain\Models;

use Illuminate\Database\Eloquent\Model;

final class TenantOperationalProjection extends Model
{
    protected $connection = 'control';

    protected $table = 'tenant_operational_projections';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['open_support_tickets' => 'integer', 'active_alerts' => 'integer', 'last_operation_at' => 'datetime', 'projected_at' => 'datetime'];
    }
}
