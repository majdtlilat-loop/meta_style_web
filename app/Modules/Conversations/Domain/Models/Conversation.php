<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Models;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Conversations\Domain\Enums\ConversationChannel;
use App\Modules\Conversations\Domain\Enums\ConversationStatus;
use App\Modules\Customers\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One thread with one phone number.
 *
 * NOTHING HERE MUTATES STATE. Taking over, returning to the AI, closing and
 * replying all go through Actions, because each needs a lock, an authorization
 * check and an audit entry that a model method could not honestly provide — the
 * same rule the Booking module's `Appointment` follows (docs/25-WHATSAPP.md §12).
 * Named in prose, not linked: a docblock link becomes an import of another
 * module's model, which Conversations may not have.
 *
 * ## `customer_id` is a RESOLUTION, not a credential
 *
 * It records who this thread turned out to be with, so staff and the AI have
 * context. It grants nothing on its own: every inbound message is still
 * verified against the provider's signature independently, and a conversation
 * that already knows its customer does not make the NEXT unsigned message
 * trustworthy. Trust is per-message, never a stored flag (ADR-070, §6).
 *
 * @property int $id
 * @property string $uuid
 * @property ConversationChannel $channel
 * @property int|null $whatsapp_account_id
 * @property int|null $customer_id
 * @property string $contact_phone
 * @property int|null $branch_id
 * @property ConversationStatus $status
 * @property string $locale
 * @property int|null $assigned_user_id
 * @property CarbonImmutable|null $last_message_at
 * @property int $consecutive_failures
 */
final class Conversation extends Model
{
    use UsesTenantConnection;

    protected $table = 'conversations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => ConversationChannel::class,
            'status' => ConversationStatus::class,
            'last_message_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $conversation): void {
            $conversation->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<WhatsAppAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(WhatsAppAccount::class, 'whatsapp_account_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function allowsAi(): bool
    {
        return $this->status->allowsAi();
    }

    /**
     * Has this thread been resolved to a customer we know?
     *
     * A first-time sender has not, and that is an ordinary state — no customer
     * record is invented for a number that may be a wrong number (§7).
     */
    public function isIdentified(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Threads that are still live.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ConversationStatus::openValues());
    }
}
