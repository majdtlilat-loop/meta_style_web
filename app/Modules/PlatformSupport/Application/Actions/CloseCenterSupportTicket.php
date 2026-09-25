<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\PlatformSupport\Application\CenterSupportDesk;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * A center closes its own ticket ("we are done here").
 *
 * Found through the bound tenant, locked, changed and recorded in the
 * ticket's history in one control-plane transaction. Closing a closed ticket
 * changes nothing and records nothing.
 */
final class CloseCenterSupportTicket
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CenterSupportDesk $desk,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function __invoke(User $actor, string $ticketUuid): SupportTicket
    {
        if (! $actor->hasPermission(Permission::PlatformSupportManage)) {
            throw new AuthorizationException(__('manager_support.errors.forbidden'));
        }

        $ticket = $this->desk->find($this->tenants->require()->id, $ticketUuid);

        return DB::connection('control')->transaction(function () use ($ticket, $actor): SupportTicket {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()->whereKey($ticket->getKey())->where('tenant_id', $ticket->tenant_id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'closed') {
                return $locked;
            }

            $before = ['status' => $locked->status];
            $locked->forceFill(['status' => 'closed', 'closed_at' => now(), 'last_activity_at' => now()])->save();

            DB::connection('control')->table('support_ticket_history')->insert([
                'ticket_id' => $locked->getKey(),
                'event' => 'closed_by_center',
                'actor_type' => 'center',
                'actor_id' => (string) $actor->getKey(),
                'actor_label' => (string) $actor->name,
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode(['status' => 'closed'], JSON_THROW_ON_ERROR),
                'reason' => null,
                'occurred_at' => now(),
            ]);

            return $locked;
        });
    }
}
