<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Application\CenterSupportDesk;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketMessage;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * A center answers in its own ticket.
 *
 * The ticket is found through the BOUND tenant (another center's is not
 * found). A center reply is never internal. Replying to a resolved ticket
 * reopens it (the shared {@see ReplyToSupportTicket} rule); a closed one must
 * be reopened first.
 */
final class ReplyToCenterSupportTicket
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CenterSupportDesk $desk,
        private readonly ReplyToSupportTicket $reply,
        private readonly CenterSupportAttachments $attachments,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws DomainException
     */
    public function __invoke(User $actor, string $ticketUuid, string $body, array $files = []): SupportTicketMessage
    {
        if (! $actor->hasPermission(Permission::PlatformSupportManage)) {
            throw new AuthorizationException(__('manager_support.errors.forbidden'));
        }

        $ticket = $this->desk->find($this->tenants->require()->id, $ticketUuid);

        if ($ticket->status === 'closed') {
            throw new DomainException(__('manager_support.errors.closed'));
        }

        if (trim($body) === '') {
            throw new DomainException(__('manager_support.errors.invalid'));
        }

        $stored = $files === [] ? [] : $this->attachments->store((string) $ticket->uuid, $files);

        try {
            return ($this->reply)($ticket, $body, false, 'center', (string) $actor->getKey(), (string) $actor->name, $stored);
        } catch (Throwable $e) {
            $this->attachments->discard($stored);

            if ($e instanceof DomainException) {
                // Closed between the read above and the lock inside the reply.
                throw new DomainException(__('manager_support.errors.closed'));
            }

            throw $e;
        }
    }
}
