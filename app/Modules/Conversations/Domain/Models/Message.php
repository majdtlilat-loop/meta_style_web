<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Conversations\Domain\Enums\DeliveryState;
use App\Modules\Conversations\Domain\Enums\MessageAuthor;
use App\Modules\Conversations\Domain\Enums\MessageDirection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One thing that was said, in one direction, by one kind of author.
 *
 * ## What it stores, and what it does not
 *
 * The TEXT, because a center has to be able to see what was said to their
 * customer in their name. Not the provider's raw notification body, not the
 * sender's WhatsApp profile name, not a media payload — none of which is needed
 * once the message has been read, and all of which would widen what a leak of
 * this table costs (docs/25-WHATSAPP.md §18).
 *
 * ## Delivery state belongs to OUTBOUND only
 *
 * An inbound message arrived; there is no delivery outcome of ours to report,
 * so `delivery_state` is null. An outbound one starts `pending`, becomes `sent`
 * when the provider names it, and may end `unknown` — which is never retried by
 * generic code (§9).
 *
 * @property int $id
 * @property string $uuid
 * @property int $conversation_id
 * @property MessageDirection $direction
 * @property MessageAuthor $author_type
 * @property int|null $author_user_id
 * @property string $body
 * @property string|null $provider_message_id
 * @property DeliveryState|null $delivery_state
 * @property string|null $failure_code
 * @property string|null $template_name
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 */
final class Message extends Model
{
    use UsesTenantConnection;

    protected $table = 'messages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'author_type' => MessageAuthor::class,
            'delivery_state' => DeliveryState::class,
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $message): void {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function isInbound(): bool
    {
        return $this->direction === MessageDirection::Inbound;
    }

    /**
     * Should staff be shown this message as something that needs looking at?
     *
     * An `unknown` delivery is the case this exists for: nobody can tell
     * whether the customer received it, and only a person can decide what to do
     * about that (§9).
     */
    public function needsAttention(): bool
    {
        return $this->delivery_state?->needsAttention() === true;
    }
}
