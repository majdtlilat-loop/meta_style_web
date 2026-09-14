<?php

declare(strict_types=1);

namespace App\Modules\Branches\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Concerns\HasMedia;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Catalog\Domain\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A physical location of a center.
 *
 * @property int $id
 * @property string $uuid
 * @property TranslatedText $name
 * @property TranslatedText|null $address
 * @property bool $is_active
 * @property bool $is_public
 * @property bool $is_main
 * @property string $timezone
 * @property string|null $invoice_prefix Sales-owned: the start of this branch's invoice numbers
 * @property string|null $phone
 * @property string|null $whatsapp
 * @property string|null $email
 * @property string|null $map_url
 * @property string|null $latitude
 * @property string|null $longitude
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
final class Branch extends Model
{
    use HasMedia;
    use UsesTenantConnection;

    protected $table = 'branches';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => Translatable::class,
            'address' => Translatable::class,
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'is_main' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $branch): void {
            $branch->uuid ??= (string) Str::uuid();
        });
    }

    public function mediaOwnerType(): MediaOwner
    {
        return MediaOwner::Branch;
    }

    /**
     * @return HasMany<BranchWorkingHour, $this>
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(BranchWorkingHour::class)
            ->orderBy('day_of_week')
            ->orderBy('opens_at');
    }

    /**
     * @return HasMany<BranchHourException, $this>
     */
    public function hourExceptions(): HasMany
    {
        return $this->hasMany(BranchHourException::class)->orderBy('date');
    }

    /**
     * Services explicitly restricted to this branch.
     *
     * Not the same as "services available here" — a service with
     * `available_at_all_branches` is available and has no row. Use
     * {@see Service::availableAtBranch()} to ask the real question.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'branch_service');
    }

    /**
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('archived_at');
    }

    /**
     * Branches a customer may see. Active, not archived, and marked public.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_public', true)
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public static function main(): ?self
    {
        return self::query()->where('is_main', true)->first();
    }
}
