<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Sales\Domain\Enums\SaleSource;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The commercial transaction: what a customer is being charged.
 *
 * NOT a visit. A sale may point at a {@see ServiceJourney}, and nothing about it
 * is ever written back there — the visit says what happened, the sale says what
 * was charged, and they are allowed to disagree: a complimentary service, a
 * manager's discount, a product bought on the way out (docs/18-SALES.md §38).
 *
 * NOT an invoice. A draft sale is the mutable cart. Finalizing it publishes an
 * {@see Invoice}, which carries its own snapshots and is never edited again.
 *
 * Every write to a sale goes through `SaleMutation`, which locks the row and
 * recalculates the totals in the same transaction. Nothing else assigns a total.
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property int|null $customer_id
 * @property int|null $service_journey_id
 * @property int|null $active_journey_id
 * @property SaleSource $source
 * @property SaleStatus $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_total_minor
 * @property int $surcharge_total_minor
 * @property int $tax_total_minor
 * @property int $grand_total_minor
 * @property int|null $cashier_shift_id
 * @property string|null $idempotency_token
 * @property string|null $created_by_id
 * @property string|null $created_by_label
 * @property Carbon|null $finalized_at
 * @property string|null $finalized_by_id
 * @property string|null $finalized_by_label
 * @property Carbon|null $voided_at
 * @property string|null $voided_by_id
 * @property string|null $voided_by_label
 * @property string|null $void_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Sale extends Model
{
    use UsesTenantConnection;

    protected $table = 'sales';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => SaleSource::class,
            'status' => SaleStatus::class,
            'subtotal_minor' => 'integer',
            'discount_total_minor' => 'integer',
            'surcharge_total_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'grand_total_minor' => 'integer',
            'finalized_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $sale): void {
            $sale->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<ServiceJourney, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(ServiceJourney::class, 'service_journey_id');
    }

    /**
     * @return HasMany<SaleItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<SaleAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(SaleAdjustment::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @return BelongsTo<CashierShift, $this>
     */
    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function currencyCode(): Currency
    {
        return Currency::from($this->currency);
    }

    public function money(int $minor): Money
    {
        return Money::fromMinor($minor, $this->currencyCode());
    }

    public function isDraft(): bool
    {
        return $this->status === SaleStatus::Draft;
    }

    public function isFinalized(): bool
    {
        return $this->status === SaleStatus::Finalized;
    }

    public function isVoided(): bool
    {
        return $this->status === SaleStatus::Voided;
    }
}
