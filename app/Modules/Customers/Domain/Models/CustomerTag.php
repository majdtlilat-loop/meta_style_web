<?php

declare(strict_types=1);

namespace App\Modules\Customers\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A label a center puts on customers: VIP, New, Frequent, Follow Up.
 *
 * Manual only. No rules, no automatic segmentation, no campaigns — a tag is
 * something a member of staff applies, and later Marketing and Reports read
 * (docs/13-ROADMAP.md Phase 5 §12).
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property bool $is_active
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class CustomerTag extends Model
{
    use UsesTenantConnection;

    protected $table = 'customer_tags';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $tag): void {
            $tag->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsToMany<Customer, $this>
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_customer_tag');
    }

    /**
     * @param  Builder<CustomerTag>  $query
     * @return Builder<CustomerTag>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
