<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\Models\QueueTicketEvent;

/**
 * Turns queue rows into the JSON a STAFF client renders.
 *
 * ## Not the display feed
 *
 * {@see DisplayFeed} is the guest surface and emits a number and a place. This
 * one is behind authentication and may name the customer, because a host has to
 * know who they are calling. The two are separate classes precisely so that
 * adding a field here can never leak it onto a television
 * (docs/17-QUEUE.md §14).
 *
 * ## Still an allow-list
 *
 * Every field is named. Never `$model->toArray()` minus a deny-list: the next
 * column somebody adds would be published by default
 * (docs/08-AUDIT-SECURITY.md).
 */
final class QueuePresenter
{
    /**
     * @return array<string, mixed>
     */
    public function ticket(QueueTicket $ticket, User $viewer, bool $withHistory = false): array
    {
        $journey = $ticket->journey;
        $stage = $ticket->stage;

        return [
            'uuid' => $ticket->uuid,
            'number' => $ticket->display_number,
            'state' => $ticket->state->value,
            'priority' => $ticket->priority,

            'department' => $ticket->department === null ? null : [
                'uuid' => $ticket->department->uuid,
                'name' => $ticket->department->name->get(),
            ],
            'service_point' => $ticket->servicePoint === null ? null : [
                'uuid' => $ticket->servicePoint->uuid,
                'code' => $ticket->servicePoint->display_code,
                'name' => $ticket->servicePoint->name->get(),
            ],

            'issued_at' => $ticket->issued_at->toIso8601String(),
            'first_called_at' => $ticket->first_called_at?->toIso8601String(),
            'last_called_at' => $ticket->last_called_at?->toIso8601String(),
            'held_at' => $ticket->held_at?->toIso8601String(),
            'serving_started_at' => $ticket->serving_started_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'call_count' => $ticket->call_count,
            'skip_count' => $ticket->skip_count,
            'hold_reason' => $ticket->hold_reason,
            'waited_minutes' => $ticket->waitedMinutes(),

            /*
             * The visit this number belongs to. A staff client needs to be able
             * to open it, and the customer's name is what a host reads out.
             * `journey.customer` for a walk-in, the appointment's for a booked
             * visit — `customerId()` hides which (docs/16 §22).
             */
            'visit' => $journey === null ? null : [
                'uuid' => $journey->uuid,
                'source' => $journey->source->value,
                'customer_name' => $journey->customer->name ?? $journey->appointment?->customer?->name,
            ],

            'stage' => $stage === null ? null : [
                'uuid' => $stage->uuid,
                'status' => $stage->status->value,
                'service_name' => $stage->serviceName()?->get(),
                'employee_name' => $stage->employee?->name->get(),
            ],

            'history' => $withHistory
                ? $ticket->events->map(fn (QueueTicketEvent $e): array => $this->event($e))->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function servicePoint(QueueServicePoint $point): array
    {
        return [
            'uuid' => $point->uuid,
            'name' => $point->name->get(),
            'display_code' => $point->display_code,
            'ticket_prefix' => $point->ticket_prefix,
            'branch_uuid' => $point->branch?->uuid,
            'department' => $point->department === null ? null : [
                'uuid' => $point->department->uuid,
                'name' => $point->department->name->get(),
            ],
            'resource' => $point->resource === null ? null : [
                'uuid' => $point->resource->uuid,
                'name' => (string) $point->resource->name,
            ],
            'is_active' => $point->is_active,
            'sort_order' => $point->sort_order,
            'archived_at' => $point->archived_at?->toIso8601String(),
        ];
    }

    /**
     * One history row.
     *
     * The actor is a LABEL, not a user record: "who called this customer" is
     * operational, and publishing an employee's internal identity through a
     * queue endpoint is more than the question needs (§14).
     *
     * @return array<string, mixed>
     */
    private function event(QueueTicketEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'type' => $event->type->value,
            'from_state' => $event->from_state,
            'to_state' => $event->to_state,
            'service_point_code' => $event->servicePoint?->display_code,
            'reason' => $event->reason,
            'actor' => $event->actor_label,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }
}
