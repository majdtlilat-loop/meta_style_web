<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Models;

use App\Kernel\Localization\Casts\Translatable;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One promotional image or video on one waiting-room screen.
 *
 * The FILE is a `media_items` row owned by the screen (`MediaOwner::QueueDisplay`),
 * validated on its bytes and stored by the media kernel. This row only says how
 * the screen plays it: where in the order, whether it is paused, and an optional
 * short caption per content language. Plain text only — the screen renders it
 * as text, never as markup (ADR-038).
 *
 * @property int $id
 * @property string $uuid
 * @property int $queue_display_id
 * @property int $media_item_id
 * @property int $sort_order
 * @property bool $is_enabled
 * @property TranslatedText|null $caption
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read QueueDisplay|null $display
 * @property-read MediaItem|null $mediaItem
 */
final class QueueDisplayMedia extends Model
{
    use UsesTenantConnection;

    /** A caption is one line over a picture, read from across a room. */
    public const CAPTION_MAX = 120;

    /** Alt text describes an image for a screen reader; the kernel column is wider. */
    public const ALT_MAX = 190;

    protected $table = 'queue_display_media';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
            'caption' => Translatable::class,
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<QueueDisplay, $this>
     */
    public function display(): BelongsTo
    {
        return $this->belongsTo(QueueDisplay::class, 'queue_display_id');
    }

    /**
     * @return BelongsTo<MediaItem, $this>
     */
    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class, 'media_item_id');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mediaItem?->mime_type, 'video/');
    }
}
