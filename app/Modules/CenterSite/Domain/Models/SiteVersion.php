<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Domain\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\CenterSite\Domain\SiteVersionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One saved state of a center's public landing page.
 *
 * `content` is stored exactly as the SiteContent normalizer returned it, and is
 * re-hydrated on every read: a row written by an older release is brought up
 * to the current shape, and anything the current catalog no longer knows is
 * dropped rather than rendered.
 *
 * @property int $id
 * @property string $uuid
 * @property SiteVersionStatus $status
 * @property int $version
 * @property array<string, mixed> $content
 * @property int|null $restored_from_version
 * @property int|null $saved_by_user_id
 * @property int|null $published_by_user_id
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SiteVersion extends Model
{
    use UsesTenantConnection;

    protected $table = 'site_versions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SiteVersionStatus::class,
            'content' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $version): void {
            $version->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function savedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by_user_id');
    }

    /**
     * @param  Builder<SiteVersion>  $query
     * @return Builder<SiteVersion>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', SiteVersionStatus::Draft->value);
    }

    /**
     * @param  Builder<SiteVersion>  $query
     * @return Builder<SiteVersion>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', SiteVersionStatus::Published->value);
    }

    /**
     * @param  Builder<SiteVersion>  $query
     * @return Builder<SiteVersion>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', SiteVersionStatus::Archived->value)->orderByDesc('version');
    }
}
