<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application\Actions;

use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ChangeSupportTicket
{
    public function __invoke(SupportTicket $ticket, string $status, string $priority, ?int $assigneeId, string $actorId, string $actorLabel, string $reason): SupportTicket
    {
        if (! in_array($status, ['open', 'in_progress', 'waiting_center', 'resolved', 'closed'], true) || ! in_array($priority, ['low', 'normal', 'high', 'urgent'], true) || trim($reason) === '') {
            throw new DomainException('Valid status, priority, and reason are required.');
        }

        return DB::connection('control')->transaction(function () use ($ticket, $status, $priority, $assigneeId, $actorId, $actorLabel, $reason): SupportTicket {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $before = ['status' => $locked->status, 'priority' => $locked->priority, 'assigned_platform_user_id' => $locked->assigned_platform_user_id];
            $after = ['status' => $status, 'priority' => $priority, 'assigned_platform_user_id' => $assigneeId];
            $locked->forceFill($after + ['resolved_at' => $status === 'resolved' ? now() : null, 'closed_at' => $status === 'closed' ? now() : null, 'last_activity_at' => now()])->save();
            DB::connection('control')->table('support_ticket_history')->insert(['ticket_id' => $locked->id, 'event' => 'state_changed', 'actor_type' => 'platform', 'actor_id' => $actorId, 'actor_label' => $actorLabel, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'reason' => trim($reason), 'occurred_at' => now()]);

            return $locked;
        });
    }
}
