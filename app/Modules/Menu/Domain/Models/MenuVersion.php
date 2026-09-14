<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Menu\Domain\MenuPresentation;
use App\Modules\Menu\Domain\MenuVersionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One saved state of a center's menu appearance.
 *
 * Draft, live and history are one table because they are the same document in
 * three states. A separate `menu_drafts` table would duplicate every column and
 * turn "publish" into a copy between schemas — which is where the two shapes
 * start to drift.
 *
 * @property int $id
 * @property string $uuid
 * @property MenuVersionStatus $status
 * @property int $version
 * @property string $template_key
 * @property array<string, string|bool|int> $theme
 * @property list<array{key: string, visible: bool, config: array<string, string|bool|int>}> $sections
 * @property int|null $published_by_user_id
 * @property Carbon|null $published_at
 */
final class MenuVersion extends Model
{
    use UsesTenantConnection;

    protected $table = 'menu_versions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MenuVersionStatus::class,
            'theme' => 'array',
            'sections' => 'array',
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
     * Re-validates on the way out.
     *
     * A row could have been written by an older release whose catalog allowed
     * a template or section this one no longer does. Rebuilding through
     * {@see MenuPresentation} means the renderer only ever sees values the
     * CURRENT code understands, rather than trusting the database.
     */
    public function presentation(): MenuPresentation
    {
        return MenuPresentation::fromArray([
            'template_key' => $this->template_key,
            'theme' => $this->theme,
            'sections' => $this->sections,
        ]);
    }

    /**
     * @param  Builder<MenuVersion>  $query
     * @return Builder<MenuVersion>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', MenuVersionStatus::Draft->value);
    }

    /**
     * @param  Builder<MenuVersion>  $query
     * @return Builder<MenuVersion>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', MenuVersionStatus::Published->value);
    }

    /**
     * @param  Builder<MenuVersion>  $query
     * @return Builder<MenuVersion>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', MenuVersionStatus::Archived->value)->orderByDesc('version');
    }
}
