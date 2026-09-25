<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Concerns;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Security\Exceptions\MissingKeyVersion;
use App\Livewire\Center\Booking\Support\BookingMessages;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The booking screens' one way of calling the engine.
 *
 * Every refusal the engine can give — a slot taken a second ago, a missing
 * permission, a plan without `booking`, a record out of scope — becomes a
 * localized sentence in `$error` instead of a Livewire error modal. The
 * server stays the authority; this only decides how a "no" is shown
 * (docs/15-BOOKING.md §7: a slot that was offered may still be refused).
 */
trait RunsBookingActions
{
    public string $notice = '';

    public string $error = '';

    /**
     * Runs a change. Impure by definition: the work it runs writes, and may
     * set this component's own state.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $work
     * @return TResult|null null when the engine refused
     *
     * @phpstan-impure
     */
    protected function attempt(callable $work): mixed
    {
        $this->error = '';
        $this->notice = '';

        try {
            return $work();
        } catch (BookingFailed|JourneyFailed|EntitlementRequired|AuthorizationException|ModelNotFoundException|MissingKeyVersion $e) {
            $this->error = BookingMessages::for($e);

            return null;
        }
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
        $this->error = '';
    }

    protected function viewer(): User
    {
        $user = auth('web')->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
