<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application\Listeners;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Modules\Rayan\Domain\Events\AssistantSettingsChanged;

/**
 * Writes the audit entry for a change to the center's WhatsApp assistant.
 *
 * RAYAN may not reach the audit trail (docs/27-RAYAN.md §10), so it announces
 * the change and the CHANNEL — which answers customers through that assistant
 * and already audits its own configuration (docs/25-WHATSAPP.md §20) —
 * records it. Synchronous, in the same request as the save: a settings change
 * moves no money and there is nothing to wait for.
 *
 * Recorded: who, whether the assistant was switched on or off, the model
 * before and after, and whether the tone changed. Never the tone's text.
 */
final class AuditAssistantSettings
{
    public function __construct(private readonly Audit $audit) {}

    public function handle(AssistantSettingsChanged $event): void
    {
        $this->audit->record(new AuditEvent(
            action: 'rayan.settings.updated',
            category: AuditCategory::Config,
            actor: new Actor(ActorType::Staff, AuditSource::Web, $event->actorUuid, $event->actorName),
            severity: AuditSeverity::Info,
            targetType: 'rayan_settings',
            before: $event->before,
            after: $event->after + ['tone_changed' => $event->toneChanged],
        ));
    }
}
