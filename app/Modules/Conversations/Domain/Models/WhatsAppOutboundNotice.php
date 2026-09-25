<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Conversations\Domain\Enums\NoticePurpose;
use App\Modules\Conversations\Domain\Enums\NoticeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One business-initiated WhatsApp notice: the decision, and the attempt.
 *
 * `unique(purpose, source_type, source_uuid)` makes it exactly one per fact —
 * one confirmation decision per booking, however often the booking event is
 * heard or the reconciler runs (docs/25-WHATSAPP.md §22). It never holds the
 * customer's phone number or the rendered text: the thread and the message row
 * hold those, under their own rules.
 *
 * NOTHING HERE MUTATES STATE. Claiming, settling and retrying happen in
 * `GuestBookingConfirmations`, through conditional writes whose affected-row
 * count is the answer.
 *
 * @property int $id
 * @property string $uuid
 * @property NoticePurpose $purpose
 * @property string $source_type
 * @property string $source_uuid
 * @property int|null $customer_id
 * @property int|null $branch_id
 * @property int|null $conversation_id
 * @property int|null $message_id
 * @property NoticeStatus $status
 * @property string|null $reason
 * @property string|null $locale
 * @property int $attempts
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WhatsAppOutboundNotice extends Model
{
    use UsesTenantConnection;

    protected $table = 'whatsapp_outbound_notices';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => NoticePurpose::class,
            'status' => NoticeStatus::class,
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $notice): void {
            $notice->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
