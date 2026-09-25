<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use App\Livewire\Center\Customers\Concerns\FormatsLocalDates;
use App\Modules\Customers\Application\Actions\ManageCustomerNotes;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Staff notes about a customer: read with `customer.note.view`, written and
 * removed with `customer.note.manage`, each note at its own visibility.
 *
 * Staff-only, always. The body is never copied into the audit trail — the
 * Action records that a note was written or removed, by whom, and nothing it
 * says (docs/13 Phase 5 §22). Which notes a viewer may read is the
 * presenter's decision, the same one the API makes.
 */
final class NotesPanel extends Component
{
    use FormatsLocalDates;

    public const MAX_LENGTH = 2000;

    #[Locked]
    public string $customer = '';

    public string $noteBody = '';

    public string $noteVisibility = 'internal';

    public string $notice = '';

    public string $noticeTone = 'success';

    public function addNote(ManageCustomerNotes $notes, CustomerQuery $customers): void
    {
        $this->validate([
            'noteBody' => ['required', 'string', 'max:'.self::MAX_LENGTH],
            'noteVisibility' => ['required', 'in:'.NoteVisibility::Internal->value.','.NoteVisibility::ManagerOnly->value],
        ], [], [
            'noteBody' => __('manager_customers.notes.body'),
            'noteVisibility' => __('manager_customers.notes.visibility'),
        ]);

        try {
            $notes->add(
                $customers->find($this->customer, $this->user()),
                $this->noteBody,
                $this->user(),
                NoteVisibility::from($this->noteVisibility),
            );
        } catch (AuthorizationException $refused) {
            $this->flash($refused->getMessage(), 'danger');

            return;
        } catch (ValidationException $invalid) {
            $this->addError('noteBody', (string) collect($invalid->errors())->flatten()->first());

            return;
        }

        $this->reset(['noteBody', 'noteVisibility']);
        $this->flash(__('manager_customers.notes.added'));
    }

    public function deleteNote(string $noteUuid, ManageCustomerNotes $notes, CustomerQuery $customers): void
    {
        try {
            $customer = $customers->find($this->customer, $this->user());

            /** @var InternalNote|null $note */
            $note = InternalNote::query()
                ->for(NoteOwner::Customer, (int) $customer->getKey())
                ->where('uuid', $noteUuid)
                ->first();

            if (! $note instanceof InternalNote) {
                $this->flash(__('manager_customers.notes.missing'), 'danger');

                return;
            }

            $notes->delete($customer, $note, $this->user());
        } catch (AuthorizationException $refused) {
            $this->flash($refused->getMessage(), 'danger');

            return;
        }

        $this->flash(__('manager_customers.notes.deleted'));
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(CustomerQuery $customers, CustomerPresenter $presenter): View
    {
        $user = $this->user();

        try {
            $customer = $customers->find($this->customer, $user);
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        if (! $user->hasPermission(Permission::CustomerNoteView)) {
            return view('livewire.center.customers.panel-denied');
        }

        $notes = array_map(fn (array $note): array => $note + [
            'visibility_label' => $note['visibility'] === NoteVisibility::ManagerOnly->value
                ? __('manager_customers.notes.managers_only') : __('manager_customers.notes.all_staff'),
            'visibility_tone' => $note['visibility'] === NoteVisibility::ManagerOnly->value ? 'warning' : 'neutral',
            'when' => $this->localDateTime($note['created_at']),
            'ago' => $note['created_at'] === null ? null : $this->relative($note['created_at']),
        ], $presenter->notesFor($customer, $user));

        return view('livewire.center.customers.notes-panel', [
            'notes' => $notes,
            'canManage' => $user->hasPermission(Permission::CustomerNoteManage),
            'maxLength' => self::MAX_LENGTH,
        ]);
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
