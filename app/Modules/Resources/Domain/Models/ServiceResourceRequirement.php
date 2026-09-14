<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Catalog\Domain\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This service needs N of that resource type."
 *
 * A laser session needing a room and a machine is two of these. Two rooms is
 * one row with `quantity = 2`, never two rows — the unique key on
 * (service, type) is what stops a requirement being split across rows that
 * could disagree (docs/13-ROADMAP.md Phase 7 §5).
 *
 * Changing a requirement never touches an existing booking. The concrete
 * resources that booking holds are already written into `resource_reservations`
 * and stay there; only the next booking reads this table (§43).
 *
 * @property int $id
 * @property int $service_id
 * @property int $resource_type_id
 * @property int $quantity
 */
final class ServiceResourceRequirement extends Model
{
    use UsesTenantConnection;

    protected $table = 'service_resource_requirements';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<ResourceType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(ResourceType::class, 'resource_type_id');
    }
}
