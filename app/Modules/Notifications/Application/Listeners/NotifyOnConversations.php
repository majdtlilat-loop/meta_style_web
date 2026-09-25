<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application\Listeners;

use App\Kernel\Authorization\Permission;
use App\Kernel\Database\AfterCommit;
use App\Kernel\Usage\Events\UsageThresholdReached;
use App\Kernel\Usage\UsageStatus;
use App\Modules\Conversations\Domain\Events\ProviderSendFailed;
use App\Modules\Conversations\Domain\Events\TakeoverRequested;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Application\StaffTargets;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Enums\NotificationType;

/**
 * Phase 13's staff alerts: somebody is waiting, something is broken, something
 * is running out.
 *
 * ## The direction, again
 *
 * Conversations and `Kernel\Usage` emit FACTS and know nothing about
 * notifications. This module listens. Three architecture tests enforce that
 * nothing imports `Modules\Notifications`, which is what keeps a failure here
 * from being able to damage a conversation or a usage counter (ADR-067).
 *
 * ## Everything runs after the commit
 *
 * The conversation is already `human_requested` and the usage row is already
 * written before any of this is attempted. So a notification that fails loses a
 * notification — never the hand-off, never the count. `AfterCommit` reports the
 * failure and drops it.
 *
 * ## Who is told
 *
 * Staff holding the relevant PERMISSION in the relevant BRANCH. Never a role
 * name, never everybody in the center (docs/23-NOTIFICATIONS.md §5). A
 * conversation with no branch yet reaches everybody with the permission, for
 * the same reason it appears in everybody's inbox: a thread nobody can see is a
 * customer nobody answers.
 */
final class NotifyOnConversations
{
    public function __construct(
        private readonly NotificationCenter $center,
        private readonly StaffTargets $staff,
        private readonly AfterCommit $afterCommit,
    ) {}

    /**
     * A customer needs a person.
     *
     * Keyed on the CONVERSATION's uuid, so the schema's
     * `unique(type, source_type, source_uuid)` means one alert per thread —
     * however many times it bounces back into `human_requested` over its life.
     * A thread that is handed off, picked up, returned to the assistant and
     * handed off again does not page the desk twice (§10).
     */
    public function handleTakeoverRequested(TakeoverRequested $event): void
    {
        $this->afterCommit->run('notifications.conversation_takeover_requested', function () use ($event): void {
            $recipients = $this->staff->withPermission(Permission::ConversationTakeover, $event->branchId);

            if ($recipients === []) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: NotificationType::ConversationTakeoverRequested,
                sourceType: 'conversation',
                sourceUuid: $event->conversationUuid,
                /*
                 * The REASON code and the customer's name — enough to triage
                 * from the inbox. Never the message text: a notification is not
                 * where customer-authored words are stored, and the thread is
                 * one click away (§7).
                 */
                params: array_filter([
                    'reason' => $event->reason,
                    'customer' => $event->customerName,
                ], static fn (?string $value): bool => $value !== null),
                recipients: $recipients,
                branchId: $event->branchId,
            ));
        });
    }

    /**
     * The provider refused an outbound message.
     *
     * Only a refusal reaches here — an `unknown` delivery is visible in the
     * thread and deliberately does not page anybody, because alerting on every
     * transient blip trains staff to ignore the alert that means the
     * integration is genuinely broken (docs/25-WHATSAPP.md §13).
     */
    public function handleProviderSendFailed(ProviderSendFailed $event): void
    {
        $this->afterCommit->run('notifications.whatsapp_provider_failed', function () use ($event): void {
            // `whatsapp.manage`: the people who can actually fix credentials,
            // not everybody who can read a thread.
            $recipients = $this->staff->withPermission(Permission::WhatsAppManage, $event->branchId);

            if ($recipients === []) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: NotificationType::WhatsAppProviderFailed,
                sourceType: 'conversation',
                sourceUuid: $event->conversationUuid,
                // A safe provider code. Never a raw provider message, which can
                // echo the customer's own text back into an inbox.
                params: ['code' => $event->failureCode],
                recipients: $recipients,
                branchId: $event->branchId,
            ));
        });
    }

    /**
     * An allowance crossed a threshold.
     *
     * `Kernel\Usage` has ALREADY made this exactly-once, through
     * `unique(resource, period_start, threshold)` — the event is dispatched
     * only by the call that inserted the alert row. So this listener does no
     * deduplication of its own; the notification's own unique index is a second
     * belt, keyed on the same period and threshold
     * (docs/26-USAGE-QUOTAS.md §13).
     *
     * Only AI resources are announced. WhatsApp volume is metered and reported
     * on the dashboard but has no enforced allowance to run out of, so a
     * "threshold" there would be a warning about nothing (§3).
     */
    public function handleUsageThreshold(UsageThresholdReached $event): void
    {
        if ($event->group !== 'ai') {
            return;
        }

        $type = $event->status === UsageStatus::Exhausted
            ? NotificationType::AiQuotaExhausted
            : NotificationType::AiQuotaWarning;

        $this->afterCommit->run('notifications.'.$type->value, function () use ($event, $type): void {
            // `ai.manage`: an allowance is a commercial matter for whoever
            // configures the assistant, not for whoever answers messages.
            $recipients = $this->staff->withPermission(Permission::AiManage);

            if ($recipients === []) {
                return;
            }

            $this->center->deliver(new NotificationRequest(
                type: $type,
                // The period and threshold are part of the identity, so next
                // month's 85% is a different notification from this month's.
                sourceType: 'usage',
                sourceUuid: $event->resource.':'.$event->periodStart->toDateString().':'.$event->threshold,
                params: [
                    'resource' => $event->resource,
                    'percent' => $event->threshold,
                    'used' => $event->used,
                    'allowance' => $event->allowance,
                ],
                recipients: $recipients,
            ));
        });
    }
}
