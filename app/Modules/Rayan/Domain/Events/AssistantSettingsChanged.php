<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Events;

/**
 * A person changed what the center decides about its assistant: on or off,
 * the approved model, the tone (docs/27-RAYAN.md §5).
 *
 * A fact for the audit trail, which RAYAN itself may not reach — the assistant
 * can touch neither money nor the audit log (ConversationsBoundaryTest). It
 * carries identifiers and the before/after of the two switches, never the
 * tone's text: that is the center's wording, not an audit fact, and whether it
 * changed is all an investigation needs.
 */
final readonly class AssistantSettingsChanged
{
    /**
     * @param  array{enabled: bool, model: string|null}  $before
     * @param  array{enabled: bool, model: string|null}  $after
     */
    public function __construct(
        public string $actorUuid,
        public string $actorName,
        public array $before,
        public array $after,
        public bool $toneChanged,
    ) {}
}
