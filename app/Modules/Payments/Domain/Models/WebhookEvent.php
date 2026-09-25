<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Payments\Domain\Enums\WebhookResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A distinct provider callback and what it led to. Never its body
 * (docs/19-PAYMENTS.md §22).
 *
 * @property int $id
 * @property string $uuid
 * @property int $gateway_account_id
 * @property string $provider
 * @property string|null $provider_event_id
 * @property string $fingerprint
 * @property string $event_type
 * @property bool $signature_verified
 * @property int|null $payment_id
 * @property WebhookResult $result
 * @property string|null $error_code
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
final class WebhookEvent extends Model
{
    use UsesTenantConnection;

    protected $table = 'payment_webhook_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'result' => WebhookResult::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            $event->uuid ??= (string) Str::uuid();
        });
    }
}
