<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\Queue\Domain\Models\QueueTicketEvent;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The reception queue, shaped for the Manager screen: ticket cards, the five
 * lanes, the day's figures — and which buttons each viewer gets.
 *
 * ## The buttons are decided here, not in a template
 *
 * "May this host skip this ticket right now?" is the ticket state machine, the
 * queue permission split (`queue.call` vs `queue.manage`), the stage state for
 * start/finish and two entitlements. Deciding it in Blade would be a second,
 * quieter copy of those rules. These flags are PRESENTATION: every Action still
 * locks the ticket and refuses on the server whatever a client sends.
 *
 * In particular a HELD ticket is never offered "call": `held → called` is not
 * an edge of the map (TicketState), so the button could only ever fail. Held
 * tickets resume — back to their original place — or start directly.
 *
 * ## Live minutes, derived
 *
 * Waiting and serving minutes are computed from the stored instants at render
 * time, never stored (docs/17-QUEUE.md §24). Times are shown on the BRANCH's
 * wall clock.
 *
 * ## Staff surface
 *
 * The customer's NAME is shown — a host has to know who they are calling. Never
 * a phone or an email: nothing here reads a contact field.
 */
final class QueueBoardView
{
    /** @var array<int, string>|null */
    private ?array $timezones = null;

    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * @param  list<QueueTicket>  $tickets
     * @return list<array<string, mixed>>
     */
    public function cards(array $tickets, User $viewer, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $flags = $this->flags($viewer);

        return array_map(fn (QueueTicket $ticket): array => $this->card($ticket, $flags, $now), $tickets);
    }

    /**
     * Waiting · Called · Serving · On hold · Done — derived from state, never
     * stored.
     *
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, list<array<string, mixed>>>
     */
    public function lanes(array $cards): array
    {
        $lanes = ['waiting' => [], 'called' => [], 'serving' => [], 'held' => [], 'done' => []];

        foreach ($cards as $card) {
            $state = (string) $card['state'];
            $lanes[array_key_exists($state, $lanes) ? $state : 'done'][] = $card;
        }

        // Finished tickets newest first: the desk wants what just happened.
        usort($lanes['done'], static fn (array $a, array $b): int => strcmp((string) ($b['closed_at'] ?? ''), (string) ($a['closed_at'] ?? '')));

        return $lanes;
    }

    /**
     * The day at a glance, from the tickets already on screen.
     *
     * @param  list<QueueTicket>  $tickets
     * @return array{waiting: int, called: int, serving: int, held: int, completed: int, cancelled: int, average_wait: int|null, longest_wait: int|null}
     */
    public function summary(array $tickets, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $counts = ['waiting' => 0, 'called' => 0, 'serving' => 0, 'held' => 0, 'completed' => 0, 'cancelled' => 0];
        $waits = [];
        $longest = null;

        foreach ($tickets as $ticket) {
            $counts[$ticket->state->value]++;

            if ($ticket->first_called_at !== null) {
                $waits[] = $this->minutesBetween($ticket->issued_at, $ticket->first_called_at);
            }

            if ($ticket->state === TicketState::Waiting) {
                $waited = $this->minutesBetween($ticket->issued_at, $now);
                $longest = $longest === null ? $waited : max($longest, $waited);
            }
        }

        return $counts + [
            // The same measure §24 names: issued → first call.
            'average_wait' => $waits === [] ? null : (int) round(array_sum($waits) / count($waits)),
            'longest_wait' => $longest,
        ];
    }

    /**
     * One ticket with its history, for the detail drawer.
     *
     * @return array<string, mixed>
     */
    public function detail(QueueTicket $ticket, User $viewer, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $timezone = $this->timezone((int) $ticket->branch_id);

        return $this->card($ticket, $this->flags($viewer), $now) + [
            'branch' => $ticket->branch?->name->get(),
            'branch_uuid' => $ticket->branch?->uuid,
            'first_called' => $this->clock($ticket->first_called_at, $timezone),
            'held' => $this->clock($ticket->held_at, $timezone),
            'history' => $ticket->relationLoaded('events')
                ? $ticket->events->map(fn (QueueTicketEvent $event): array => [
                    'type' => $event->type->value,
                    'to_state' => $event->to_state,
                    'destination' => $event->servicePoint?->display_code,
                    'reason' => $event->reason,
                    'actor' => $event->actor_label,
                    'at' => $this->clock($event->occurred_at, $timezone),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * A checked-in visit with no number yet, as a row.
     *
     * @param  array{journey: ServiceJourney, stage: JourneyStage}  $pending
     * @return array<string, mixed>
     */
    public function pending(array $pending, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $journey = $pending['journey'];
        $stage = $pending['stage'];

        return [
            'stage_uuid' => $stage->uuid,
            'journey_uuid' => $journey->uuid,
            'customer' => $journey->customer->name ?? $journey->appointment?->customer?->name,
            'walk_in' => $journey->isWalkIn(),
            'service' => $stage->serviceName()?->get(),
            'employee' => $stage->employee?->name->get(),
            'arrived' => $this->clock($journey->arrived_at, $this->timezone($journey->branchId())),
            'waiting_minutes' => $journey->arrived_at === null ? null : $this->minutesBetween($journey->arrived_at, $now),
        ];
    }

    /**
     * What this viewer may do on this screen at all.
     *
     * @return array<string, bool>
     */
    public function flags(User $viewer): array
    {
        $queue = $this->entitlements->enabled('queue_management');
        $journey = $this->entitlements->enabled('booking');

        return [
            'entitled' => $queue,
            'call' => $queue && $viewer->hasPermission(Permission::QueueCall),
            'manage' => $queue && $viewer->hasPermission(Permission::QueueManage),
            'start' => $queue && $journey && $viewer->hasPermission(Permission::JourneyStageStart),
            'finish' => $queue && $journey && $viewer->hasPermission(Permission::JourneyStageComplete),
            'abandon' => $queue && $journey
                && $viewer->hasPermission(Permission::QueueManage)
                && $viewer->hasPermission(Permission::JourneyManage),
            // The 80mm paper is the `printing` capability (docs/18 §§ entitlements).
            'print' => $queue
                && $this->entitlements->enabled('printing')
                && $viewer->hasPermission(Permission::QueueTicketPrint),
            'walk_in' => $queue && $journey
                && $viewer->hasPermission(Permission::JourneyWalkInCreate)
                && $viewer->hasPermission(Permission::QueueManage),
            'setup' => $viewer->hasPermission(Permission::QueueDisplayManage),
        ];
    }

    /**
     * @param  array<string, bool>  $flags
     * @return array<string, mixed>
     */
    private function card(QueueTicket $ticket, array $flags, CarbonImmutable $now): array
    {
        $timezone = $this->timezone((int) $ticket->branch_id);
        $state = $ticket->state;
        $stage = $ticket->stage;
        $journey = $ticket->journey;
        $open = $state->isOpen();
        $serving = $state === TicketState::Serving;
        $stageWaiting = $stage instanceof JourneyStage && $stage->status === StageStatus::Waiting;
        $stageInService = $stage instanceof JourneyStage && $stage->status === StageStatus::InService;

        $elapsed = match ($state) {
            TicketState::Waiting, TicketState::Held => $this->minutesBetween($ticket->issued_at, $now),
            TicketState::Called => $this->minutesBetween($ticket->last_called_at ?? $ticket->issued_at, $now),
            TicketState::Serving => $this->minutesBetween($ticket->serving_started_at ?? $ticket->issued_at, $now),
            default => null,
        };

        return [
            'uuid' => $ticket->uuid,
            'number' => $ticket->display_number,
            'state' => $state->value,
            'priority' => $ticket->priority,
            'priority_level' => match (true) {
                $ticket->priority >= 20 => 'urgent',
                $ticket->priority >= 10 => 'high',
                $ticket->priority > 0 => 'raised',
                default => 'normal',
            },
            // The name a host reads out. A walk-in's customer is on the visit,
            // a booked one's on the appointment — `customerId()` hides which.
            'customer' => $journey->customer->name ?? $journey?->appointment?->customer?->name,
            'walk_in' => $journey?->isWalkIn() ?? false,
            'visit_uuid' => $journey?->uuid,
            'service' => $stage?->serviceName()?->get(),
            'employee' => $stage?->employee?->name->get(),
            'stage_status' => $stage?->status->value,
            'department' => $ticket->department?->name->get(),
            'destination' => $ticket->servicePoint === null ? null : [
                'uuid' => $ticket->servicePoint->uuid,
                'code' => $ticket->servicePoint->display_code,
                'name' => $ticket->servicePoint->name->get(),
            ],
            'issued' => $this->clock($ticket->issued_at, $timezone),
            'called' => $this->clock($ticket->last_called_at, $timezone),
            'serving_since' => $this->clock($ticket->serving_started_at, $timezone),
            'closed' => $this->clock($ticket->closed_at, $timezone),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
            'elapsed_minutes' => $elapsed,
            // How long somebody has been left standing: amber from a quarter
            // of an hour, red from half an hour. Presentation, not a rule.
            'elapsed_tone' => $this->tone($state, $elapsed),
            'waited_minutes' => $ticket->waitedMinutes(),
            'call_count' => $ticket->call_count,
            'skip_count' => $ticket->skip_count,
            'hold_reason' => $ticket->hold_reason,
            'close_reason' => $ticket->close_reason,
            'can' => [
                'call' => $flags['call'] && $state === TicketState::Waiting,
                'recall' => $flags['call'] && $state === TicketState::Called,
                'skip' => $flags['call'] && $state === TicketState::Called,
                'hold' => $flags['manage'] && in_array($state, [TicketState::Waiting, TicketState::Called], true),
                'resume' => $flags['manage'] && $state === TicketState::Held,
                'start' => $flags['start'] && $open && ! $serving && $stageWaiting,
                'finish' => $flags['finish'] && $serving && $stageInService,
                'transfer' => $flags['manage'] && $open && ! $serving,
                'priority' => $flags['manage'] && $open,
                'cancel' => $flags['manage'] && $open && ! $serving,
                'abandon' => $flags['abandon'] && $open && $journey instanceof ServiceJourney,
                'print' => $flags['print'],
            ],
        ];
    }

    private function tone(TicketState $state, ?int $elapsed): string
    {
        if ($elapsed === null || ! in_array($state, [TicketState::Waiting, TicketState::Held, TicketState::Called], true)) {
            return 'neutral';
        }

        return $elapsed >= 30 ? 'danger' : ($elapsed >= 15 ? 'warning' : 'neutral');
    }

    private function timezone(int $branchId): string
    {
        $this->timezones ??= Branch::query()->pluck('timezone', 'id')
            ->map(static fn (mixed $zone): string => (string) $zone)
            ->all();

        return $this->timezones[$branchId] ?? 'UTC';
    }

    private function clock(?CarbonInterface $instant, string $timezone): ?string
    {
        if ($instant === null) {
            return null;
        }

        return BranchClock::toLocal(CarbonImmutable::instance($instant)->utc(), $timezone)->format('H:i');
    }

    private function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
    }
}
