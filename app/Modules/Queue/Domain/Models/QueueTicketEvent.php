<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain\Models;

use App\Kernel\Tenancy\Concerns\UsesTenantConnection;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Queue\Domain\Enums\TicketEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One thing that happened to a ticket. Appended, never rewritten.
 *
 * ## The recall problem this exists to solve
 *
 * A host calls A012. Nobody comes. They call again. If `called_at` were simply
 * overwritten, the second call would erase the first and the record would say
 * the customer was called once, promptly. Both calls are facts, and "called
 * three times then skipped" is exactly what somebody reviewing a busy Saturday
 * needs to see (docs/17-QUEUE.md §13).
 *
 * ## The announcement identifier
 *
 * `uuid` is what the public display feed exposes as `announcement_id`. A
 * television polls every three seconds; it remembers which announcements it has
 * already spoken and says nothing when the state has not changed. A recall is a
 * NEW event with a new uuid, so it speaks again — which is the whole point of
 * pressing recall (correction 2).
 *
 * The numeric id is never published: it would tell a public screen how many
 * queue events the center has ever recorded.
 *
 * ## The sequence is allocated under the ticket lock
 *
 * 1, 2, 3 … per ticket, computed while the ticket row is held `FOR UPDATE`, so
 * two desks acting at the same instant cannot both decide they are number four.
 * The unique index is the backstop that says so even if a future path forgets
 * the lock (§5, correction 5).
 *
 * @property int $id
 * @property string $uuid
 * @property int $queue_ticket_id
 * @property int $sequence
 * @property TicketEventType $type
 * @property string|null $from_state
 * @property string|null $to_state
 * @property int|null $service_point_id
 * @property int|null $department_id
 * @property string|null $reason
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property Carbon $occurred_at
 */
final class QueueTicketEvent extends Model
{
    use UsesTenantConnection;

    protected $table = 'queue_ticket_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TicketEventType::class,
            'sequence' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            $event->uuid ??= (string) Str::uuid();
        });
    }

    /**
     * @return BelongsTo<QueueTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(QueueTicket::class, 'queue_ticket_id');
    }

    /**
     * Where the customer was sent by THIS event.
     *
     * Recorded per call rather than only as the ticket's current destination: a
     * transfer must not erase where somebody was sent first (§16).
     *
     * @return BelongsTo<QueueServicePoint, $this>
     */
    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(QueueServicePoint::class, 'service_point_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isAnnounceable(): bool
    {
        return in_array($this->type->value, TicketEventType::announceableValues(), true);
    }
}
