<?php

declare(strict_types=1);

namespace App\Kernel\Media\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The database record for a stored file.
 *
 * The row never holds an absolute path or a URL — only the disk-relative path
 * `MediaStore` produced. Tenant isolation comes from the disk being rooted at
 * `tenants/{key}/`, and an absolute path stored in the database would escape
 * that the first time a dump was restored somewhere else
 * (docs/09-STORAGE.md §1).
 *
 * @property int $id
 * @property string $uuid
 * @property MediaOwner $owner_type
 * @property int $owner_id
 * @property MediaCollection $collection
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property TranslatedText|null $alt_text
 * @property int $sort_order
 */
final class MediaItem extends Model
{
    use UsesTenantConnection;

    protected $table = 'media_items';

    protected $guarded = [];

    /**
     * The stored path is an internal detail. A client gets `url` and `uuid`;
     * handing it the path invites it to build its own URLs, which is how a
     * private collection ends up publicly linked.
     *
     * @var list<string>
     */
    protected $hidden = ['path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_type' => MediaOwner::class,
            'collection' => MediaCollection::class,
            'alt_text' => Translatable::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @param  Builder<MediaItem>  $query
     * @return Builder<MediaItem>
     */
    public function scopeFor(Builder $query, MediaOwner $owner, int $ownerId): Builder
    {
        return $query->where('owner_type', $owner->value)
            ->where('owner_id', $ownerId)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * A URL a browser can fetch.
     *
     * Only for public collections. A private one has no public URL by
     * construction; it is reached through a permission check and a short-lived
     * signed URL, which is a different call with a different audit story
     * (docs/09-STORAGE.md §7).
     */
    public function url(): ?string
    {
        if (! $this->collection->isPublic()) {
            return null;
        }

        // A center's public disk is rooted under tenants/{key}, which the
        // global /storage link cannot reach: on the center's own host the file
        // is served by the center media route instead.
        $request = app()->bound('request') ? request() : null;
        if ($request !== null
            && app(TenantContext::class)->isBound()
            && app(PlatformHosts::class)->centerSlugFromHost($request->getHost()) !== null) {
            return $request->getSchemeAndHttpHost().'/media/'.$this->path;
        }

        return Storage::disk($this->collection->disk())->url($this->path);
    }

    /**
     * Removes the row AND the file.
     *
     * Deliberately together: a row without a file renders a broken image, and a
     * file without a row is storage nobody will ever reclaim.
     */
    public function purge(MediaStore $store): void
    {
        $store->delete($this->collection, $this->path);

        $this->delete();
    }
}
