<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An add-on chosen for one booked service, with its price and duration as they
 * were at booking time.
 *
 * A row rather than a JSON column on the item. Add-ons carry money and minutes
 * that Phase 9's POS and Phase 10's reports will sum and group, and none of
 * that is reachable inside a JSON blob without the MySQL-specific functional
 * indexing this schema deliberately avoids (ADR-033).
 *
 * @property int $id
 * @property int $appointment_item_id
 * @property int|null $service_addon_id
 * @property TranslatedText $name
 * @property int $price_minor
 * @property int $duration_minutes
 * @property string $currency
 * @property int $sort_order
 */
final class AppointmentItemAddon extends Model
{
    use UsesTenantConnection;

    protected $table = 'appointment_item_addons';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['name' => Translatable::class];
    }

    /**
     * @return BelongsTo<AppointmentItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(AppointmentItem::class, 'appointment_item_id');
    }

    /**
     * @return BelongsTo<ServiceAddon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(ServiceAddon::class, 'service_addon_id');
    }

    public function price(): Money
    {
        return Money::fromMinor(
            $this->price_minor,
            Currency::tryFrom($this->currency) ?? Currency::default(),
        );
    }
}
