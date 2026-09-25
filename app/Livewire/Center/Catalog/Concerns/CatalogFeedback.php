<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog\Concerns;

use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Catalog\CatalogErrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * The outcome message every catalog component shows, and the one place a
 * refused Action becomes a translated notice instead of an error page.
 *
 * Hiding a button is presentation only: each method calls an Action, and the
 * Action decides. This just reports what it decided.
 */
trait CatalogFeedback
{
    public string $notice = '';

    public string $noticeTone = 'success';

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    protected function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    /**
     * Runs one change and reports it.
     *
     * @param  callable(): mixed  $change
     */
    protected function attempt(callable $change, ?string $success = null): bool
    {
        try {
            $change();
        } catch (AuthorizationException) {
            $this->flash(__('ui.errors.forbidden'), 'danger');

            return false;
        } catch (ValidationException $e) {
            $this->flash(CatalogErrors::first($e), 'danger');

            return false;
        } catch (ModelNotFoundException) {
            $this->flash(__('manager_catalog.errors.not_found'), 'danger');

            return false;
        }

        if ($success !== null) {
            $this->flash($success);
        }

        return true;
    }

    protected function actor(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
