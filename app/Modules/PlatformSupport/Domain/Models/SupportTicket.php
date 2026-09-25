<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Domain\Models;

use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class SupportTicket extends Model
{
    protected $connection = 'control';

    protected $table = 'support_tickets';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(static function (self $ticket): void {
            $ticket->uuid ??= (string) Str::uuid();
        });
    }

    /** @return HasMany<SupportTicketMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }

    /** @return BelongsTo<TenantModel, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id');
    }
}
