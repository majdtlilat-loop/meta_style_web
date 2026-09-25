<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Notifications\Domain\Enums\NotificationSeverity;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The FACT a notification is about, once — not a copy per person.
 *
 * `unique(type, source_type, source_uuid)` is the whole idempotency story: the
 * same appointment heard twice, a reminder sweep that overlaps itself and a
 * reconciliation pass all try to insert the same row and exactly one survives.
 * No de-duplication logic, no "have I already sent this" query that races
 * (docs/23-NOTIFICATIONS.md §12).
 *
 * ## What is NOT here
 *
 * No rendered text. `params` holds the small allow-listed values the message
 * needs, and the sentence is built at READ time in the reader's own language —
 * so a customer who switches to Kurdish sees their whole inbox in it. No HTML,
 * ever, and nothing customer-authored (§7).
 *
 * No delivery status either. In-app is the only channel in Phase 12, and for
 * in-app "delivered" means the recipient row exists. Inventing a `sent` /
 * `failed` column for a thing that cannot fail would be a status that lies
 * (§10).
 *
 * @property int $id
 * @property string $uuid
 * @property NotificationType $type
 * @property NotificationSeverity $severity
 * @property string $source_type
 * @property string $source_uuid
 * @property int|null $branch_id
 * @property array<string, string|int|float|bool|null> $params
 * @property Carbon $created_at
 */
final class Notification extends Model
{
    use UsesTenantConnection;

    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'severity' => NotificationSeverity::class,
            'params' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $notification): void {
            $notification->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return HasMany<NotificationRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(NotificationRecipient::class);
    }
}
