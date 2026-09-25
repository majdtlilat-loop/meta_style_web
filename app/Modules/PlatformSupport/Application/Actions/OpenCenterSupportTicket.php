<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application\Actions;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\PlatformSupport\Application\CenterSupportAttachments;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketMessage;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * A center asks Meta Style for help.
 *
 * The center is the BOUND tenant, never a value from the page; the author is
 * the signed-in staff member, who needs `platform_support.manage`. The ticket
 * itself is created by {@see CreateSupportTicket}, which also tells the
 * platform team.
 */
final class OpenCenterSupportTicket
{
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CreateSupportTicket $create,
        private readonly CenterSupportAttachments $attachments,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     *
     * @throws AuthorizationException
     * @throws DomainException
     */
    public function __invoke(User $actor, string $subject, string $body, string $priority, array $files = []): SupportTicket
    {
        if (! $actor->hasPermission(Permission::PlatformSupportManage)) {
            throw new AuthorizationException(__('manager_support.errors.forbidden'));
        }

        if (trim($subject) === '' || trim($body) === '' || ! in_array($priority, self::PRIORITIES, true)) {
            throw new DomainException(__('manager_support.errors.invalid'));
        }

        $ticket = ($this->create)(
            $this->tenants->require()->id,
            $subject,
            $body,
            $priority,
            'center',
            (string) $actor->getKey(),
            (string) $actor->name,
        );

        if ($files !== []) {
            $stored = $this->attachments->store((string) $ticket->uuid, $files);

            try {
                /** @var SupportTicketMessage|null $first */
                $first = SupportTicketMessage::query()->where('ticket_id', $ticket->getKey())->orderBy('id')->first();
                foreach ($stored as $attachment) {
                    $first?->attachments()->create($attachment);
                }
            } catch (Throwable $e) {
                $this->attachments->discard($stored);

                throw $e;
            }
        }

        return $ticket;
    }
}
