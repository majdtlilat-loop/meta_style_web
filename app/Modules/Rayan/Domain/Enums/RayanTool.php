<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Enums;

/**
 * THE ALLOW-LIST. Every operation the assistant is permitted to request.
 *
 * ## Why an enum and not a registry of strings
 *
 * Because "the model may only call tools that exist" has to be true by
 * construction, not by a check somebody remembers to write. A model that emits
 * `run_sql` or `App\Modules\Finance\...` produces a name that is not a case
 * here, {@see tryFrom()} returns null, and the call is refused before any
 * handler is looked up (docs/27-RAYAN.md §11).
 *
 * There is deliberately:
 *
 *   - no SQL tool, and no tool that takes a query, a table or a column;
 *   - no tool that takes a class name, an action name or a route;
 *   - no generic "call this endpoint" tool;
 *   - no customer-create tool — a customer is created by the Booking Engine's
 *     own resolver, from a VERIFIED phone number, as part of making a booking;
 *   - no tool that reads Finance, Audit, staff notes, other customers, or
 *     anything about employees beyond what a customer already sees on a
 *     booking page (§10).
 *
 * ## Ten tools, in two groups
 *
 * The read tools answer questions. The four mutating ones change something a
 * customer will turn up expecting, and each re-validates ownership, branch,
 * entitlement and booking state in application code — the model's request is
 * a REQUEST, never an instruction (§12).
 */
enum RayanTool: string
{
    // ---- Reading: what the center offers ------------------------------
    case ListBranches = 'list_branches';
    case ListServices = 'list_services';
    case GetServiceDetails = 'get_service_details';
    case GetAvailableSlots = 'get_available_slots';

    // ---- Reading: this customer's own things --------------------------
    case GetCustomerContext = 'get_customer_context';
    case GetCustomerBookings = 'get_customer_bookings';
    case GetBookingDetails = 'get_booking_details';

    // ---- Changing a booking -------------------------------------------
    case CreateBooking = 'create_booking';
    case RescheduleBooking = 'reschedule_booking';
    case CancelBooking = 'cancel_booking';

    /**
     * Does this tool CHANGE anything?
     *
     * Used to decide what must be audited and what may be attempted when the
     * conversation has not been resolved to a customer. A read about the
     * center's own catalog is safe for anybody; changing a booking is not.
     */
    public function mutates(): bool
    {
        return match ($this) {
            self::CreateBooking, self::RescheduleBooking, self::CancelBooking => true,
            default => false,
        };
    }

    /**
     * Does this tool need the conversation to be about a KNOWN customer before
     * it can be attempted at all?
     *
     * TRUE only for the two that are about a person's WHOLE relationship with
     * the center. "What do I have with you" and "list my bookings" are
     * meaningless without an established identity, and answering them for an
     * unresolved sender would be answering them for whoever happens to hold
     * that phone.
     *
     * The three that name ONE booking are deliberately absent, because there
     * are two legitimate ways to reach one and only the handler can tell them
     * apart (§12):
     *
     *   - the resolved customer owns it, or
     *   - the caller supplied its reference AND its verification code, which
     *     grants access to that single booking and nothing else.
     *
     * `create_booking` is absent too, and that is the important one: a
     * first-time customer is exactly who most needs to be able to book, and the
     * Booking Engine's own resolver creates their record from the VERIFIED
     * phone number.
     */
    public function requiresIdentifiedCustomer(): bool
    {
        return match ($this) {
            self::GetCustomerContext,
            self::GetCustomerBookings => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $tool): string => $tool->value, self::cases());
    }
}
