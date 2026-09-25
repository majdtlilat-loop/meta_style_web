<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class SupportTicketMessage extends Model
{
    protected $connection = 'control';

    protected $table = 'support_ticket_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $message): void {
            $message->uuid ??= (string) Str::uuid();
        });
    }

    /** @return HasMany<SupportTicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'message_id');
    }
}
