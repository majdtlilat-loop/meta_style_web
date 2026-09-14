<?php

declare(strict_types=1);

namespace App\Kernel\Notes\Concerns;

use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\Models\InternalNote;
use App\Kernel\Notes\NoteOwner;
use App\Kernel\Notes\NoteVisibility;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internal notes on a model, over a stable string key rather than a morph.
 *
 * @phpstan-require-extends Model
 */
trait HasInternalNotes
{
    abstract public function noteOwnerType(): NoteOwner;

    /**
     * @return HasMany<InternalNote, $this>
     */
    public function internalNotes(): HasMany
    {
        return $this->hasMany(InternalNote::class, 'owner_id')
            ->where('owner_type', $this->noteOwnerType()->value)
            ->latest('id');
    }

    public function addInternalNote(
        string $body,
        ?User $author = null,
        NoteVisibility $visibility = NoteVisibility::Internal,
    ): InternalNote {
        /** @var InternalNote $note */
        $note = $this->internalNotes()->create([
            'owner_type' => $this->noteOwnerType(),
            'body' => $body,
            'visibility' => $visibility,
            'author_user_id' => $author?->getKey(),
        ]);

        return $note;
    }
}
