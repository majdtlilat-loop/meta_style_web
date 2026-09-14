<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Kernel\Time\TimeWindow;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Employees\Domain\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One booked service within an appointment.
 *
 * READ THE SNAPSHOTS, NOT THE CATALOG. `price_minor`, `duration_minutes`,
 * `currency` and `service_name` are what the customer agreed to. The `service`
 * relation exists so a screen can link back to the live record and so reports
 * can group by it — it is not where this item's price comes from
 * (docs/13-ROADMAP.md Phase 6 §3).
 *
 * This row answers "what was reserved". It deliberately carries no state about
 * what actually happened — no started_at, no performed_by, no stage. Phase 7's
 * Service Journey answers that question in its own table, referencing this one
 * (§38).
 *
 * @property int $id
 * @property string $uuid
 * @property int $appointment_id
 * @property int|null $service_id
 * @property int|null $service_variation_id
 * @property int|null $employee_id
 * @property EmployeeSelection $employee_selection
 * @property int $position
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $duration_minutes
 * @property int $price_minor
 * @property string $currency
 * @property TranslatedText $service_name
 * @property TranslatedText|null $variation_name
 * @property string|null $customer_note
 */
final class AppointmentItem extends Model
{
    use UsesTenantConnection;

    protected $table = 'appointment_items';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'employee_selection' => EmployeeSelection::class,
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'service_name' => Translatable::class,
            'variation_name' => Translatable::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * The live catalog record, when it still exists.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<ServiceVariation, $this>
     */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(ServiceVariation::class, 'service_variation_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The rooms, chairs and devices this booked service holds.
     *
     * PLANNED. What was actually used during the visit is
     * `journey_stage_resources`, which carries its own intervals and maydiffer
     * after an operational swap (Phase 7 §25).
     *
     * @return HasMany<ResourceReservation, $this>
     */
    public function resourceReservations(): HasMany
    {
        return $this->hasMany(ResourceReservation::class, 'appointment_item_id');
    }

    /**
     * @return HasMany<AppointmentItemAddon, $this>
     */
    public function addons(): HasMany
    {
        return $this->hasMany(AppointmentItemAddon::class)->orderBy('sort_order')->orderBy('id');
    }

    public function window(): TimeWindow
    {
        return new TimeWindow($this->starts_at->utc(), $this->ends_at->utc());
    }

    /**
     * The agreed price, from the snapshot.
     *
     * Uses the currency stored on this row rather than the center's current
     * setting, so a historical booking keeps its meaning if a center ever
     * switches currency.
     */
    public function price(): Money
    {
        return Money::fromMinor(
            $this->price_minor,
            Currency::tryFrom($this->currency) ?? Currency::default(),
        );
    }

    public function wasCustomerChoice(): bool
    {
        return $this->employee_selection === EmployeeSelection::Specific;
    }
}
