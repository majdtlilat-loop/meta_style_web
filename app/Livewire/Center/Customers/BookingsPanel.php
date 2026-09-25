<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers;

use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\CustomerProfileAppointments;
use App\Modules\Customers\Application\CustomerQuery;
use App\View\Label;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The customer page's bookings: what is coming up and what was booked before.
 *
 * Booking's own read (`appointment.view` + branch scope) and presenter; the
 * times are the BOOKED branch-local wall clock the presenter already gives,
 * never the server's. Read-only — the calendar is where bookings change.
 */
final class BookingsPanel extends Component
{
    #[Locked]
    public string $customer = '';

    public function render(CustomerQuery $customers, CustomerProfileAppointments $appointments, AppointmentPresenter $presenter): View
    {
        $user = $this->user();

        try {
            $customer = $customers->find($this->customer, $user);
            $found = $appointments->rowsForCustomer($user, (int) $customer->getKey(), $presenter, app()->getLocale());
        } catch (AuthorizationException) {
            return view('livewire.center.customers.panel-denied');
        }

        $label = static fn (array $row): array => $row + ['status_label' => Label::for('appointment_status', (string) $row['status'])];

        return view('livewire.center.customers.bookings-panel', [
            'upcoming' => array_map($label, $found['upcoming']),
            'past' => array_map($label, $found['past']),
            'calendarUrl' => route('center.calendar'),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
