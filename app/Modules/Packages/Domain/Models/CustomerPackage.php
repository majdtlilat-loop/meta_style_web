<?php

declare(strict_types=1);

namespace App\Modules\Packages\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Packages\Domain\Enums\CustomerPackageStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A package a customer bought, as it was bought.
 *
 * Usable while it is active and `now` is before `expires_at`. Expiry is
 * derived, never written; cancelling is the only status change
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§13, 18).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property int $package_definition_id
 * @property int $branch_id
 * @property TranslatedText $name
 * @property int $price_minor
 * @property string $currency
 * @property int $validity_days
 * @property int $sale_id
 * @property int $sale_item_id
 * @property Carbon $activated_at
 * @property Carbon $expires_at
 * @property CustomerPackageStatus $status
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by_id
 * @property string|null $cancelled_by_label
 * @property string|null $cancel_reason
 * @property-read Collection<int, CustomerPackageItem> $items
 */
final class CustomerPackage extends Model
{
    use UsesTenantConnection;

    protected $table = 'customer_packages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'package_definition_id' => 'integer',
            'branch_id' => 'integer',
            'name' => Translatable::class,
            'price_minor' => 'integer',
            'validity_days' => 'integer',
            'sale_id' => 'integer',
            'sale_item_id' => 'integer',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
            'status' => CustomerPackageStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $package): void {
            $package->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<CustomerPackageItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerPackageItem::class)->orderBy('id');
    }

    /**
     * Who holds it — for staff lists, which show the NAME only.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The definition it was bought from. What it COVERS is this row's own
     * snapshot, never the definition's current items (§13).
     *
     * @return BelongsTo<PackageDefinition, $this>
     */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(PackageDefinition::class, 'package_definition_id');
    }

    public function isExpired(CarbonInterface $now): bool
    {
        return $now->greaterThanOrEqualTo($this->expires_at);
    }

    public function isUsable(CarbonInterface $now): bool
    {
        return $this->status === CustomerPackageStatus::Active
            && $now->greaterThanOrEqualTo($this->activated_at)
            && ! $this->isExpired($now);
    }

    /** active · expired · cancelled — for display; never stored. */
    public function state(CarbonInterface $now): string
    {
        if ($this->status === CustomerPackageStatus::Cancelled) {
            return 'cancelled';
        }

        return $this->isExpired($now) ? 'expired' : 'active';
    }
}
