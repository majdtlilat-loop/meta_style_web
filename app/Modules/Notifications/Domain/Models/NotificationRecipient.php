<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One person's copy of one notification: their inbox row, and their read state.
 *
 * ## Two id spaces, never mixed
 *
 * `recipient_kind` + `recipient_id` together identify the person. There is no
 * foreign key, because the two kinds live in different tables (`users` and
 * `customer_accounts`) with unrelated id sequences — and that is exactly why
 * EVERY query filters on both columns. A query that filtered on the id alone
 * would hand staff user 7 the notifications of customer account 7
 * (docs/23-NOTIFICATIONS.md §4).
 *
 * `unique(notification_id, recipient_kind, recipient_id)` makes a second
 * attempt at the same person a no-op rather than a duplicate in their inbox.
 *
 * @property int $id
 * @property string $uuid
 * @property int $notification_id
 * @property RecipientKind $recipient_kind
 * @property int $recipient_id
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property-read Notification|null $notification
 */
final class NotificationRecipient extends Model
{
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'notification_recipients';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_kind' => RecipientKind::class,
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $recipient): void {
            $recipient->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
