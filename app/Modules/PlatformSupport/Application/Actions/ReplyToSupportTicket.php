<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application\Actions;

use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketMessage;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ReplyToSupportTicket
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    /** @param list<array{disk:string,path:string,original_name:string,mime:string,size:int,sha256:string}> $attachments */
    public function __invoke(SupportTicket $ticket, string $body, bool $internal, string $authorType, ?string $authorId, string $authorLabel, array $attachments = []): SupportTicketMessage
    {
        if (trim($body) === '') {
            throw new DomainException('A message is required.');
        }
        if ($authorType !== 'platform' && $internal) {
            throw new DomainException('Only platform staff may create internal notes.');
        }

        $message = DB::connection('control')->transaction(function () use ($ticket, $body, $internal, $authorType, $authorId, $authorLabel, $attachments): SupportTicketMessage {
            /** @var SupportTicket $locked */
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            if ($locked->status === 'closed') {
                throw new DomainException('A closed ticket cannot receive replies.');
            }
            /** @var SupportTicketMessage $message */
            $message = $locked->messages()->create(['author_type' => $authorType, 'author_id' => $authorId, 'author_label' => $authorLabel, 'is_internal' => $internal, 'body' => trim($body)]);
            foreach ($attachments as $attachment) {
                $message->attachments()->create($attachment);
            }
            $locked->forceFill(['last_activity_at' => now(), 'status' => $locked->status === 'resolved' ? 'open' : $locked->status, 'resolved_at' => $locked->status === 'resolved' ? null : $locked->resolved_at])->save();

            return $message;
        });

        if ($authorType !== 'platform') {
            $center = (string) DB::connection('control')->table('tenants')->where('id', $ticket->tenant_id)->value('name');
            $this->notifier->notify('support.center_replied', 'info', 'ticket_replied', [
                'reference' => $ticket->reference,
                'center' => $center,
            ], $ticket->tenant_id, '/support/'.$ticket->uuid);
        }

        return $message;
    }
}
