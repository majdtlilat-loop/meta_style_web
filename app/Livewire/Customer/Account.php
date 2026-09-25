<?php

declare(strict_types=1);

namespace App\Livewire\Customer;

use App\Http\Presenters\CustomerBenefits;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Modules\Booking\Application\Actions\IssueVerificationCode;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\VerificationCode;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Exceptions\NotificationsFailed;
use App\Modules\Reviews\Application\PublicReview;
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

    /**
     * The verification code just issued, shown once.
     *
     * Cleared by every other action on this page. There is no path that puts it
     * back: once it is gone the raw value exists nowhere, and the customer
     * regenerates again (docs/24-BOOKING-VERIFICATION.md §11).
     */
    public string $issuedCode = '';

    /** Which booking the code above belongs to. */
    public string $issuedFor = '';

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
        $this->issuedCode = '';

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

    /**
     * Issues a NEW verification code for one of the customer's own bookings.
     *
     * ## There is nothing to "show" — only to regenerate
     *
     * The code is stored as an HMAC and the raw value was returned once, at
     * booking time. A later read cannot produce it, so this screen deliberately
     * offers no "show my code" button: the only honest action is a new one, and
     * issuing it RETIRES the old (docs/24-BOOKING-VERIFICATION.md §§8, 11).
     *
     * That is also what a customer actually wants here. They ask for this
     * precisely when they think somebody else has seen the previous one.
     *
     * The uuid is scoped to this account in the QUERY and the Action checks
     * ownership again — two independent checks, the same shape as
     * {@see cancel()}.
     */
    public function regenerateCode(string $uuid, IssueVerificationCode $issue): void
    {
        $this->error = '';
        $this->notice = '';
        $this->issuedCode = '';

        $account = Auth::guard('customer')->user();

        if (! $account instanceof CustomerAccount) {
            return;
        }

        $appointment = Appointment::query()
            ->where('uuid', $uuid)
            ->where('customer_id', $account->customer_id)
            ->first();

        if (! $appointment instanceof Appointment) {
            $this->error = __('That booking was not found.');

            return;
        }

        try {
            $code = $issue->forAccount($appointment, $account);
        } catch (BookingFailed|AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        /*
         * Shown on THIS render and cleared by the next action. A Livewire
         * public property is serialised into the page and posted back on every
         * subsequent request, so the window is deliberately one screen — long
         * enough to write the code down, short enough that it is not still
         * riding along twenty requests later (§11).
         */
        $this->issuedCode = VerificationCode::format($code);
        $this->issuedFor = (string) $appointment->reference;
    }

    /**
     * Marks one of their own notifications read. Somebody else's is not found,
     * which is the same answer as one that never existed
     * (docs/23-NOTIFICATIONS.md §16).
     */
    public function readNotification(string $uuid, Inbox $inbox): void
    {
        $account = Auth::guard('customer')->user();

        if (! $account instanceof CustomerAccount) {
            return;
        }

        try {
            $inbox->markRead(new Recipient(RecipientKind::Customer, (int) $account->getKey()), $uuid);
        } catch (NotificationsFailed) {
            // Nothing to say: it is not theirs, or it is already read.
        }
    }

    public function render(
        CalendarQuery $calendar,
        AppointmentPresenter $presenter,
        CustomerBenefits $benefits,
        Inbox $inbox,
        PublicReview $reviews,
    ): mixed {
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
            // Their own points, memberships and packages: an allow-list,
            // read-only (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §19).
            'benefits' => $benefits->for((int) $account->customer_id),
            // Phase 12: their own inbox, and any visit they can still review.
            // The invitation is named by its uuid — their session is the
            // authority, so no capability secret is on the page
            // (docs/22-REVIEWS.md §40).
            'notifications' => $inbox->page(new Recipient(RecipientKind::Customer, (int) $account->getKey()), 10),
            'unread' => $inbox->unreadCount(new Recipient(RecipientKind::Customer, (int) $account->getKey())),
            'invitations' => $reviews->pendingFor((int) $account->customer_id),
        ]);
    }
}
