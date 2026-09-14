<?php

declare(strict_types=1);

namespace App\Kernel\Media\Concerns;

use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Attaches media to a model without a polymorphic relation.
 *
 * Laravel's `morphMany` would store the class name in `owner_type`, which is
 * exactly what {@see MediaOwner} exists to avoid. This trait keeps the ordinary
 * `hasMany` ergonomics — `$service->media`, eager loading, counts — over a
 * stable string key.
 *
 * @phpstan-require-extends Model
 */
trait HasMedia
{
    abstract public function mediaOwnerType(): MediaOwner;

    /**
     * @return HasMany<MediaItem, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(MediaItem::class, 'owner_id')
            ->where('owner_type', $this->mediaOwnerType()->value)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * The one image a card or a list row shows.
     */
    public function primaryMedia(): ?MediaItem
    {
        /** @var MediaItem|null $first */
        $first = $this->relationLoaded('media')
            ? $this->media->first()
            : $this->media()->first();

        return $first;
    }
}
