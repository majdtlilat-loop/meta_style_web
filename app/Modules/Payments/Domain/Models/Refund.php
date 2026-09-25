<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Payments\Domain\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Money returned against one successful payment. The payment itself is never
 * touched: a refund is a second fact about it (docs/19-PAYMENTS.md §§25–26).
 *
 * @property int $id
 * @property string $uuid
 * @property int $payment_id
 * @property int $branch_id
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property string|null $provider
 * @property RefundStatus $status
 * @property string $reason
 * @property string|null $provider_refund_reference
 * @property int|null $cashier_shift_id
 * @property string|null $idempotency_token
 * @property string|null $requested_by_id
 * @property string|null $requested_by_label
 * @property Carbon $requested_at
 * @property Carbon|null $succeeded_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_code
 */
final class Refund extends Model
{
    use UsesTenantConnection;

    protected $table = 'refunds';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'status' => RefundStatus::class,
            'requested_at' => 'datetime',
            'succeeded_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $refund): void {
            $refund->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
