<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A signed-in customer looking at their own record and their own bookings.
 *
 * Separate from {@see SignIn} for the same reason the staff dashboard is
 * separate from the staff login: this route carries the `tenant` middleware and
 * that one cannot. Until a customer signs in there is no session key to resolve
 * a center from (ADR-030, ADR-041).
 *
 * ## Only ever their own
 *
 * Appointments are loaded by the account's `customer_id`, taken from the guard.
 * There is no uuid this component accepts that could point at somebody else's
 * booking, and cancellation goes through the engine, which checks ownership
 * again (docs/13-ROADMAP.md Phase 6 §27).
 *
 * Still deliberately minimal beyond that: no loyalty, no packages, no spend
 * history. Those modules do not exist, and a page promising them would be a lie
 * told to a customer.
 */
#[Layout('components.layouts.app')]
final class Account extends Component
{
    public bool $showPast = false;

    public string $notice = '';

    public string $error = '';

    public function logout(): mixed
    {
        Auth::guard('customer')->logout();

        session()->forget(StanclTenantResolver::SESSION_KEY);
        session()->invalidate();
        session()->regenerateToken();

        return $this->redirectRoute('customer.signin', navigate: true);
    }

    /**
     * Cancels one of the customer's own bookings.
     *
     * The uuid is scoped to this account in the QUERY, and the engine checks
     * ownership again — two independent checks, because this is the one place a
     * customer supplies an identifier for something that already exists.
     */
    public function cancel(string $uuid, BookingEngine $engine): void
    {
        $this->error = '';
        $this->notice = '';

        $account = Auth::guard('customer')->user();

        if (! $account instanceof CustomerAccount) {
            return;
        }

        $appointment = Appointment::query()
            ->where('uuid', $uuid)
            ->where('customer_id', $account->customer_id)
            ->first();

        if (! $appointment instanceof Appointment) {
            // Indistinguishable from a booking that does not exist. A distinct
            // "not yours" would let a customer test uuids to learn who else has
            // an appointment here (docs/08-AUDIT-SECURITY.md §19).
            $this->error = __('That booking was not found.');

            return;
        }

        try {
            $engine->cancel($appointment, BookingActor::customer($account, $account->customer->name));
        } catch (BookingFailed|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->notice = __('Your booking has been cancelled.');
    }

    public function render(CalendarQuery $calendar, AppointmentPresenter $presenter): mixed
    {
        $account = Auth::guard('customer')->user();

        if (! $account instanceof CustomerAccount) {
            return $this->redirectRoute('customer.signin', navigate: true);
        }

        $appointments = $calendar->forCustomer((int) $account->customer_id, ! $this->showPast);

        return view('livewire.customer.account', [
            'account' => $account,
            'customer' => $account->customer,
            'appointments' => $appointments
                ->map(fn (Appointment $a): array => $presenter->forCustomer($a))
                ->values()->all(),
        ]);
    }
}
