<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a Super Admin one file attached to a support ticket.
 *
 * The attachment is looked up THROUGH the ticket, so a valid attachment uuid
 * under another ticket's URL is simply not found. Always a download with the
 * stored MIME type and `nosniff` — an uploaded file is never rendered inline
 * on the platform host.
 */
final class SupportAttachmentController extends Controller
{
    public function __invoke(string $ticket, string $attachment): StreamedResponse
    {
        $record = SupportTicketAttachment::query()
            ->where('uuid', $attachment)
            ->whereIn('message_id', SupportTicket::query()
                ->where('support_tickets.uuid', $ticket)
                ->join('support_ticket_messages', 'support_ticket_messages.ticket_id', '=', 'support_tickets.id')
                ->select('support_ticket_messages.id'))
            ->firstOrFail();

        $disk = Storage::disk((string) $record->disk);
        abort_unless($disk->exists((string) $record->path), 404);

        return $disk->download((string) $record->path, (string) $record->original_name, [
            'Content-Type' => (string) $record->mime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
