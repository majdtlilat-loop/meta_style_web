<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Memberships\Domain\Enums\CustomerMembershipStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A membership a customer bought, as it was bought.
 *
 * Usable while it is active and `starts_at ≤ now < expires_at`. "Upcoming" (a
 * renewal waiting for the current term) and "expired" are derived, never
 * written; cancelling is the only status change
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§10–11, 21).
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property int $membership_plan_id
 * @property int $branch_id
 * @property TranslatedText $name
 * @property int $price_minor
 * @property string $currency
 * @property int $duration_days
 * @property int $sale_id
 * @property int $sale_item_id
 * @property Carbon $activated_at
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property CustomerMembershipStatus $status
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by_id
 * @property string|null $cancelled_by_label
 * @property string|null $cancel_reason
 * @property-read Collection<int, CustomerMembershipBenefit> $benefits
 */
final class CustomerMembership extends Model
{
    use UsesTenantConnection;

    protected $table = 'customer_memberships';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'membership_plan_id' => 'integer',
            'branch_id' => 'integer',
            'name' => Translatable::class,
            'price_minor' => 'integer',
            'duration_days' => 'integer',
            'sale_id' => 'integer',
            'sale_item_id' => 'integer',
            'activated_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'status' => CustomerMembershipStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $membership): void {
            $membership->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<CustomerMembershipBenefit, $this>
     */
    public function benefits(): HasMany
    {
        return $this->hasMany(CustomerMembershipBenefit::class)->orderBy('id');
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
     * The plan it was bought from. What it GIVES is this row's own snapshot,
     * never the plan's current terms (§10).
     *
     * @return BelongsTo<MembershipPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function isExpired(CarbonInterface $now): bool
    {
        return $now->greaterThanOrEqualTo($this->expires_at);
    }

    public function isUpcoming(CarbonInterface $now): bool
    {
        return $now->lessThan($this->starts_at);
    }

    public function isUsable(CarbonInterface $now): bool
    {
        return $this->status === CustomerMembershipStatus::Active
            && ! $this->isUpcoming($now)
            && ! $this->isExpired($now);
    }

    /** active · upcoming · expired · cancelled — for display; never stored. */
    public function state(CarbonInterface $now): string
    {
        if ($this->status === CustomerMembershipStatus::Cancelled) {
            return 'cancelled';
        }

        if ($this->isExpired($now)) {
            return 'expired';
        }

        return $this->isUpcoming($now) ? 'upcoming' : 'active';
    }
}
