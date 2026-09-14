<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Customers\Domain\Models\Customer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Internal notes on a customer.
 *
 * Staff-only, always. Nothing here reaches a customer-facing surface, and that
 * is enforced by the public resources building their output from an allow-list
 * rather than by remembering to exclude notes (docs/13-ROADMAP.md Phase 5 §11).
 *
 * THE NOTE BODY IS NEVER AUDITED. A note is free text a member of staff wrote
 * about a person; copying it into the audit trail would duplicate whatever
 * sensitive thing it contains into a second table with a different retention
 * rule and a different set of readers. The audit records that a note was
 * written, by whom, at what visibility — which is what "who added this" needs
 * — and the note itself stays in one place (§22).
 */
final class ManageCustomerNotes
{
    public function __construct(private readonly Audit $audit) {}

    public function add(
        Customer $customer,
        string $body,
        User $actingUser,
        NoteVisibility $visibility = NoteVisibility::Internal,
    ): InternalNote {
        if (! $actingUser->hasPermission(Permission::CustomerNoteManage)) {
            throw new AuthorizationException('You may not write customer notes.');
        }

        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'A note needs some text.']);
        }

        $note = $customer->addInternalNote($body, $actingUser, $visibility);

        $this->audit->record(new AuditEvent(
            action: 'crm.customer_note.created',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Customer::class,
            targetId: $customer->uuid,
            targetLabel: $customer->name,
            meta: [
                'note_uuid' => $note->uuid,
                'visibility' => $visibility->value,
                // Length, not content. Enough to tell an accidental empty save
                // from a real note without copying what it says.
                'length' => mb_strlen($body),
            ],
        ));

        return $note;
    }

    public function delete(Customer $customer, InternalNote $note, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::CustomerNoteManage)) {
            throw new AuthorizationException('You may not manage customer notes.');
        }

        // Scoped to the customer, so a note uuid from another record cannot be
        // deleted through this customer's endpoint.
        if ($note->owner_id !== $customer->id || $note->owner_type !== $customer->noteOwnerType()) {
            throw new AuthorizationException('That note does not belong to this customer.');
        }

        $uuid = $note->uuid;

        $note->delete();

        $this->audit->record(new AuditEvent(
            action: 'crm.customer_note.deleted',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Customer::class,
            targetId: $customer->uuid,
            targetLabel: $customer->name,
            meta: ['note_uuid' => $uuid],
        ));
    }

    /**
     * The notes this viewer may read.
     *
     * @return list<InternalNote>
     */
    public function visibleTo(Customer $customer, User $viewer): array
    {
        if (! $viewer->hasPermission(Permission::CustomerNoteView)) {
            return [];
        }

        /** @var list<InternalNote> $notes */
        $notes = $customer->internalNotes()
            ->get()
            ->filter(fn (InternalNote $note): bool => $viewer->hasPermission($note->visibility->requiredPermission(NoteOwner::Customer)))
            ->values()
            ->all();

        return $notes;
    }
}
