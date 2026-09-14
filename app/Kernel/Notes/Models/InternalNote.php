<?php

declare(strict_types=1);

namespace App\Kernel\Notes\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A note staff leave for each other. Never customer-facing.
 *
 * "Never" is enforced by the public resources building their output from an
 * explicit allow-list of fields rather than by excluding this relation. The
 * difference matters: an allow-list cannot be defeated by someone adding a
 * column, and a deny-list eventually is.
 *
 * @property int $id
 * @property string $uuid
 * @property NoteOwner $owner_type
 * @property int $owner_id
 * @property string $body
 * @property NoteVisibility $visibility
 * @property int|null $author_user_id
 */
final class InternalNote extends Model
{
    use UsesTenantConnection;

    protected $table = 'internal_notes';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_type' => NoteOwner::class,
            'visibility' => NoteVisibility::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $note): void {
            $note->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * @param  Builder<InternalNote>  $query
     * @return Builder<InternalNote>
     */
    public function scopeFor(Builder $query, NoteOwner $owner, int $ownerId): Builder
    {
        return $query->where('owner_type', $owner->value)
            ->where('owner_id', $ownerId)
            ->latest('id');
    }
}
