<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Sales\Domain\Concerns\ImmutableDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The published financial document. Immutable.
 *
 * It carries its own copies of everything it shows, so rendering it never reads
 * a sale line, a catalog row or a branch: a renamed service, a moved branch or a
 * changed price cannot alter a document a customer already holds
 * (docs/18-SALES.md §§14–16, ADR-054).
 *
 * Whether it was VOIDED is a fact about the sale, recorded there — the one thing
 * this row can never learn, because it is never updated.
 *
 * @property int $id
 * @property string $uuid
 * @property int $sale_id
 * @property int $branch_id
 * @property string $number
 * @property string $prefix
 * @property int $sequence_year
 * @property int $sequence_number
 * @property Carbon $issued_at
 * @property string $issued_timezone
 * @property string $center_name
 * @property TranslatedText $branch_name
 * @property TranslatedText|null $branch_address
 * @property string|null $branch_phone
 * @property string|null $customer_name
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_total_minor
 * @property int $surcharge_total_minor
 * @property int $tax_total_minor
 * @property int $grand_total_minor
 * @property list<array{type: string, basis_points: int|null, amount_minor: int, reason: string}> $adjustments
 * @property string|null $issued_by_id
 * @property string|null $issued_by_label
 */
final class Invoice extends Model
{
    use ImmutableDocument;
    use UsesTenantConnection;

    protected $table = 'invoices';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'branch_name' => Translatable::class,
            'branch_address' => Translatable::class,
            'adjustments' => 'array',
            'sequence_year' => 'integer',
            'sequence_number' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_total_minor' => 'integer',
            'surcharge_total_minor' => 'integer',
            'tax_total_minor' => 'integer',
            'grand_total_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $invoice): void {
            $invoice->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The link a customer can open, while one is live.
     *
     * @return HasOne<InvoiceShareLink, $this>
     */
    public function activeLink(): HasOne
    {
        return $this->hasOne(InvoiceShareLink::class, 'active_invoice_id');
    }

    public function currencyCode(): Currency
    {
        return Currency::from($this->currency);
    }

    public function money(int $minor): Money
    {
        return Money::fromMinor($minor, $this->currencyCode());
    }
}
