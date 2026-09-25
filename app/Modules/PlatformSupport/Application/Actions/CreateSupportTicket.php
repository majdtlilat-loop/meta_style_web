<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application\Actions;

use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateSupportTicket
{
    public function __construct(private readonly PlatformNotifier $notifier) {}

    public function __invoke(string $tenantId, string $subject, string $body, string $priority, string $authorType, ?string $authorId, string $authorLabel): SupportTicket
    {
        if (trim($subject) === '' || trim($body) === '' || ! in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
            throw new DomainException('Subject, message and a valid priority are required.');
        }

        $ticket = DB::connection('control')->transaction(function () use ($tenantId, $subject, $body, $priority, $authorType, $authorId, $authorLabel): SupportTicket {
            /** @var SupportTicket $ticket */
            $ticket = SupportTicket::query()->create(['reference' => 'SUP-'.mb_strtoupper(mb_substr((string) Str::ulid(), -10)), 'tenant_id' => $tenantId, 'subject' => trim($subject), 'status' => 'open', 'priority' => $priority, 'created_by_type' => $authorType, 'created_by_id' => $authorId, 'created_by_label' => $authorLabel, 'last_activity_at' => now()]);
            $message = $ticket->messages()->create(['author_type' => $authorType, 'author_id' => $authorId, 'author_label' => $authorLabel, 'is_internal' => false, 'body' => trim($body)]);
            DB::connection('control')->table('support_ticket_history')->insert(['ticket_id' => $ticket->id, 'event' => 'created', 'actor_type' => $authorType, 'actor_id' => $authorId, 'actor_label' => $authorLabel, 'before' => null, 'after' => json_encode(['status' => 'open', 'priority' => $priority], JSON_THROW_ON_ERROR), 'reason' => null, 'occurred_at' => now()]);

            return $ticket;
        });

        // A center asking for help is the platform team's cue; a ticket the
        // team opened itself is not news to them.
        if ($authorType !== 'platform') {
            $center = (string) DB::connection('control')->table('tenants')->where('id', $tenantId)->value('name');
            $this->notifier->notify('support.ticket_created', in_array($priority, ['high', 'urgent'], true) ? 'warning' : 'info', 'ticket_created', [
                'reference' => $ticket->reference,
                'center' => $center,
                'subject' => Str::limit(trim($subject), 80),
            ], $tenantId, '/support/'.$ticket->uuid);
        }

        return $ticket;
    }
}
