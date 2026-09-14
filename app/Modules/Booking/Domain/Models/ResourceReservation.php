<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Resources\Domain\Models\OperationalResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A concrete resource held by one booked service.
 *
 * PLANNED, not actual. This says the booking reserved Laser Machine 2; what the
 * customer was actually treated with — including a device swapped mid-session —
 * is `journey_stage_resources`, which carries its own time boundaries. The two
 * are allowed to disagree and neither rewrites the other
 * (docs/13-ROADMAP.md Phase 7 §§25, 27).
 *
 * No times of its own: the reservation is held for exactly as long as its
 * appointment item, which already stores the window. A second copy would be a
 * second thing to keep in step through every reschedule (§7).
 *
 * @property int $id
 * @property int $appointment_item_id
 * @property int $resource_id
 * @property int $quantity
 * @property TranslatedText $resource_name
 * @property TranslatedText $resource_type_name
 */
final class ResourceReservation extends Model
{
    use UsesTenantConnection;

    protected $table = 'resource_reservations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'resource_name' => Translatable::class,
            'resource_type_name' => Translatable::class,
        ];
    }

    /**
     * @return BelongsTo<AppointmentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AppointmentItem::class, 'appointment_item_id');
    }

    /**
     * @return BelongsTo<OperationalResource, $this>
     */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(OperationalResource::class, 'resource_id');
    }
}
