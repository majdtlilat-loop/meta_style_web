<?php

declare(strict_types=1);

namespace App\Modules\PlatformSupport\Application;

use App\Modules\PlatformSupport\Domain\Models\SupportTicket;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketAttachment;
use App\Modules\PlatformSupport\Domain\Models\SupportTicketMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * A center's own conversation with Meta Style, read from the control plane.
 *
 * EVERY query is scoped by the center's tenant id, which callers take from
 * the bound tenant — never from the page. A ticket, message or attachment of
 * another center is "not found" (404), exactly like one that never existed
 * (docs/08-AUDIT-SECURITY.md). Platform-internal notes never leave here.
 */
final class CenterSupportDesk
{
    /** Filter => statuses. `active` is anything Meta Style or the center still has to act on. */
    public const FILTERS = [
        'active' => ['open', 'in_progress', 'waiting_center'],
        'waiting_center' => ['waiting_center'],
        'resolved' => ['resolved'],
        'closed' => ['closed'],
        'all' => [],
    ];

    public const PER_PAGE = 15;

    /** @return LengthAwarePaginator<int, SupportTicket> */
    public function page(string $tenantId, string $filter, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        $statuses = self::FILTERS[$filter] ?? self::FILTERS['active'];

        return $this->tickets($tenantId)
            ->when($statuses !== [], static fn (Builder $query): Builder => $query->whereIn('status', $statuses))
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @return array<string, int> filter => number of tickets */
    public function counts(string $tenantId): array
    {
        /** @var array<string, int> $byStatus */
        $byStatus = $this->tickets($tenantId)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $counts = [];
        foreach (self::FILTERS as $filter => $statuses) {
            $counts[$filter] = $statuses === []
                ? array_sum($byStatus)
                : array_sum(array_intersect_key($byStatus, array_flip($statuses)));
        }

        return $counts;
    }

    /** @throws ModelNotFoundException */
    public function find(string $tenantId, string $uuid): SupportTicket
    {
        /** @var SupportTicket $ticket */
        $ticket = $this->tickets($tenantId)->where('uuid', $uuid)->firstOrFail();

        return $ticket;
    }

    /**
     * The messages a center may read: everything except internal notes.
     *
     * @return list<SupportTicketMessage>
     */
    public function conversation(SupportTicket $ticket): array
    {
        return SupportTicketMessage::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('is_internal', false)
            ->with('attachments')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * One attachment of the center's own ticket, on a message the center may
     * read. Anything else — another center's, an internal note's — is not found.
     *
     * @throws ModelNotFoundException
     */
    public function attachment(string $tenantId, string $ticketUuid, string $attachmentUuid): SupportTicketAttachment
    {
        $ticket = $this->find($tenantId, $ticketUuid);

        /** @var SupportTicketAttachment $attachment */
        $attachment = SupportTicketAttachment::query()
            ->where('uuid', $attachmentUuid)
            ->whereIn('message_id', SupportTicketMessage::query()
                ->select('id')
                ->where('ticket_id', $ticket->getKey())
                ->where('is_internal', false))
            ->firstOrFail();

        return $attachment;
    }

    /** @return Builder<SupportTicket> */
    private function tickets(string $tenantId): Builder
    {
        return SupportTicket::query()->where('tenant_id', $tenantId);
    }
}
