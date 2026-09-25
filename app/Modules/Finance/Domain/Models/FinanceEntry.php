<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Models;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Finance\Domain\Concerns\AppendOnlyRecord;
use App\Modules\Finance\Domain\Enums\EntryDirection;
use App\Modules\Finance\Domain\Enums\EntryKind;
use App\Modules\Finance\Domain\Enums\EntrySource;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One movement of the center's real money. Append-only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $branch_id
 * @property EntryDirection $direction
 * @property EntryKind $kind
 * @property int $amount_minor
 * @property string $currency
 * @property PaymentMethod $method
 * @property string|null $provider
 * @property EntrySource $source_type
 * @property string $source_uuid
 * @property int|null $cashier_shift_id
 * @property string $label
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
final class FinanceEntry extends Model
{
    use AppendOnlyRecord;
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'finance_entries';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => EntryDirection::class,
            'kind' => EntryKind::class,
            'amount_minor' => 'integer',
            'method' => PaymentMethod::class,
            'source_type' => EntrySource::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            $entry->uuid ??= (string) Str::uuid();
        });
    }

    public function money(): Money
    {
        return Money::fromMinor($this->amount_minor, Currency::tryFrom($this->currency) ?? Currency::default());
    }
}
