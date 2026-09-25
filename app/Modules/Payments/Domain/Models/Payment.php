<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\PaymentSource;
use App\Modules\Payments\Domain\Enums\PaymentStatus;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One collection attempt against one invoice.
 *
 * Status changes only through the Payments Actions, against a row locked FOR
 * UPDATE, and only along {@see PaymentStatus::allowedTransitions()}. The amount,
 * currency, invoice and method never change after creation
 * (docs/19-PAYMENTS.md §§5–7).
 *
 * @property int $id
 * @property string $uuid
 * @property int $invoice_id
 * @property int $branch_id
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property PaymentSource $source
 * @property string|null $manual_method_label
 * @property string|null $manual_reference
 * @property int|null $gateway_account_id
 * @property string|null $provider
 * @property string|null $provider_payment_reference
 * @property string|null $provider_display_code
 * @property string|null $checkout_url
 * @property Carbon|null $expires_at
 * @property int|null $cashier_shift_id
 * @property string|null $idempotency_token
 * @property string|null $collected_by_id
 * @property string|null $collected_by_label
 * @property Carbon $initiated_at
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $failure_code
 */
final class Payment extends Model
{
    use UsesTenantConnection;

    protected $table = 'payments';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'source' => PaymentSource::class,
            'expires_at' => 'datetime',
            'initiated_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<GatewayAccount, $this>
     */
    public function gatewayAccount(): BelongsTo
    {
        return $this->belongsTo(GatewayAccount::class);
    }

    /**
     * @return BelongsTo<CashierShift, $this>
     */
    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function money(int $minor): Money
    {
        return Money::fromMinor($minor, Currency::from($this->currency));
    }
}
