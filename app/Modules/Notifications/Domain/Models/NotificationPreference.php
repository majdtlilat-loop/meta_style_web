<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One person's answer for one optional switch.
 *
 * ABSENT MEANS ON. A row is written only when somebody turns something OFF or
 * back on again, so a new customer needs no rows and a new preference key
 * defaults to enabled everywhere without a backfill (docs/23-NOTIFICATIONS.md
 * §8).
 *
 * Only the keys in {@see PreferenceKey} can be stored, and only the types whose
 * `preference()` names one can be suppressed — a preference can never hide an
 * appointment somebody else cancelled or an invoice that was issued.
 *
 * @property int $id
 * @property string $uuid
 * @property RecipientKind $owner_kind
 * @property int $owner_id
 * @property PreferenceKey $preference_key
 * @property bool $enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class NotificationPreference extends Model
{
    use UsesTenantConnection;

    protected $table = 'notification_preferences';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_kind' => RecipientKind::class,
            'preference_key' => PreferenceKey::class,
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $preference): void {
            $preference->uuid ??= (string) Str::uuid();
        });
    }
}
