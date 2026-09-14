<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Operational notes on one service of a visit.
 *
 * "Move to room 3." "Needs ten minutes before the next service." "Asked to be
 * handed to Sara." Things one member of staff tells the next during a shift
 * (docs/13-ROADMAP.md Phase 7 §29).
 *
 * ## The fourth owner, on the same seam
 *
 * `Kernel/Notes` predicted these in Phase 4 and they arrive the way the others
 * did: one new {@see NoteOwner} case, no new table, no migration of existing
 * rows. A parallel notes table for the floor would need its own visibility
 * rules, its own audit shape and its own advisory — three chances to diverge
 * from the notes everyone else writes.
 *
 * ## Internal, and not a treatment record
 *
 * These never reach a customer surface. `NoteAdvisory` still applies: this is
 * not the place for medical, clinical or health information, and the warning
 * appears above the field and in the API response exactly as it does on a
 * customer note (§29).
 *
 * THE BODY IS NEVER AUDITED — the same Phase 5 rule. The trail records that a
 * note was written, by whom, at what visibility, and how long it was.
 */
final class ManageStageNotes
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function add(
        JourneyStage $stage,
        string $body,
        User $actingUser,
        NoteVisibility $visibility = NoteVisibility::Internal,
    ): InternalNote {
        $stage->loadMissing('journey.appointment');

        $this->authorize($stage, $actingUser, Permission::JourneyNoteManage);

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'A note needs some text.']);
        }

        $note = $stage->addInternalNote($body, $actingUser, $visibility);

        $this->audit->record(new AuditEvent(
            action: 'journey.stage_note.created',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: JourneyStage::class,
            targetId: $stage->uuid,
            meta: [
                'note_uuid' => $note->uuid,
                'visibility' => $visibility->value,
                // Length, not content — enough to tell an accidental empty save
                // from a real note.
                'length' => mb_strlen($body),
            ],
        ));

        return $note;
    }

    /**
     * @throws AuthorizationException
     */
    public function delete(JourneyStage $stage, InternalNote $note, User $actingUser): void
    {
        $stage->loadMissing('journey.appointment');

        $this->authorize($stage, $actingUser, Permission::JourneyNoteManage);

        // Scoped to this stage, so a note uuid belonging to another record — or
        // to a CUSTOMER — cannot be deleted through this endpoint.
        if ($note->owner_id !== $stage->id || $note->owner_type !== NoteOwner::JourneyStage) {
            throw new AuthorizationException('That note does not belong to this service.');
        }

        $uuid = $note->uuid;

        $note->delete();

        $this->audit->record(new AuditEvent(
            action: 'journey.stage_note.deleted',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: JourneyStage::class,
            targetId: $stage->uuid,
            meta: ['note_uuid' => $uuid],
        ));
    }

    /**
     * The notes this viewer may read, filtered by visibility.
     *
     * @param  iterable<InternalNote>|null  $loaded  already-loaded notes, to
     *                                               avoid a query per row on a
     *                                               board
     * @return list<InternalNote>
     */
    public function visibleTo(JourneyStage $stage, ?User $viewer, ?iterable $loaded = null): array
    {
        if ($viewer === null || ! $viewer->hasPermission(Permission::JourneyNoteView)) {
            return [];
        }

        $notes = $loaded ?? ($stage->relationLoaded('internalNotes')
            ? $stage->internalNotes
            : $stage->internalNotes()->get());

        $visible = [];

        foreach ($notes as $note) {
            if ($viewer->hasPermission($note->visibility->requiredPermission(NoteOwner::JourneyStage))) {
                $visible[] = $note;
            }
        }

        return $visible;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(JourneyStage $stage, User $user, Permission $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException('You may not write notes on a visit.');
        }

        $branchId = $stage->journey?->branchId();

        if ($branchId !== null && ! $user->canAccessBranch((int) $branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
