<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Domain\Models;

use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $number
 * @property string $tenant_id
 * @property int|null $subscription_id
 * @property int|null $plan_id
 * @property array<string, string>|null $plan_name_snapshot
 * @property array<string, mixed>|null $issuer_snapshot
 * @property string|null $billing_period
 * @property string $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property int $paid_minor
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issued_at
 * @property Carbon|null $due_at
 * @property Carbon|null $settled_at
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property string|null $voided_by_label
 * @property string|null $notes
 * @property string|null $reference
 */
final class SaasInvoice extends Model
{
    protected $connection = 'control';

    protected $table = 'saas_invoices';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['subtotal_minor' => 'integer', 'discount_minor' => 'integer', 'total_minor' => 'integer', 'paid_minor' => 'integer', 'period_start' => 'date', 'period_end' => 'date', 'issued_at' => 'datetime', 'due_at' => 'datetime', 'settled_at' => 'datetime', 'voided_at' => 'datetime', 'plan_name_snapshot' => 'array', 'issuer_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $invoice): void {
            $invoice->uuid ??= (string) Str::uuid();
        });
    }

    /** @return HasMany<SaasInvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaasInvoiceItem::class, 'invoice_id');
    }

    /** @return HasMany<SaasPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SaasPayment::class, 'invoice_id');
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['issued', 'partially_paid'], true) && $this->due_at !== null && $this->due_at->isPast();
    }

    public function balance(): int
    {
        return $this->status === 'void' ? 0 : max(0, $this->total_minor - $this->paid_minor);
    }

    /** @return BelongsTo<TenantModel, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id');
    }
}
