<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One distinct provider notification, and what was done about it.
 *
 * Meta delivers AT LEAST ONCE and retries anything that is not answered 2xx, so
 * the same message genuinely arrives several times. The
 * `unique(whatsapp_account_id, fingerprint)` index is the whole idempotency
 * mechanism: an `insertOrIgnore` that writes nothing means this has already
 * been handled, and no query anywhere asks the question
 * (docs/25-WHATSAPP.md §10).
 *
 * REJECTED notifications are recorded too, with `signature_verified = false`.
 * That is deliberate — a burst of failed signatures is exactly the thing an
 * operator needs to be able to see, and discarding them silently would make an
 * attack indistinguishable from quiet.
 *
 * No raw body. Same decision, same reasoning, as `payment_webhook_events`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $whatsapp_account_id
 * @property string $fingerprint
 * @property string $kind
 * @property string|null $provider_message_id
 * @property bool $signature_verified
 * @property string $result
 * @property string|null $error_code
 * @property CarbonImmutable $received_at
 */
final class WhatsAppWebhookEvent extends Model
{
    use UsesTenantConnection;

    protected $table = 'whatsapp_webhook_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'received_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            $event->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<WhatsAppAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }
}
