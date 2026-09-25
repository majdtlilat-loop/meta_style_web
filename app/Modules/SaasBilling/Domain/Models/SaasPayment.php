<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $invoice_id
 * @property string $tenant_id
 * @property string $method
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $reference
 * @property string|null $note
 * @property string $recorded_by_label
 * @property Carbon $received_at
 * @property Carbon|null $reversed_at
 * @property string|null $reversed_by_label
 * @property string|null $reversal_reason
 */
final class SaasPayment extends Model
{
    protected $connection = 'control';

    protected $table = 'saas_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'received_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $payment): void {
            $payment->uuid ??= (string) Str::uuid();
        });
    }
}
