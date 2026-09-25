<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

final class SupportTicketAttachment extends Model
{
    protected $connection = 'control';

    protected $table = 'support_ticket_attachments';

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(static function (self $attachment): void {
            $attachment->uuid ??= (string) Str::uuid();
        });
    }
}
