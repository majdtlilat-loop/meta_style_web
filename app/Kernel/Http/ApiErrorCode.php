<?php

declare(strict_types=1);

namespace App\Kernel\Http;

/**
 * Stable, machine-readable API error codes.
 *
 * Clients branch on these. They are NEVER localised and NEVER renamed — the
 * human-readable message is the localised part (docs/10-API-FOUNDATION.md §4.1).
 *
 * Only codes the application can currently emit are defined. The rest of the
 * catalog in docs/10 is added as its phase arrives; an enum full of unreachable
 * cases is documentation pretending to be code.
 */
enum ApiErrorCode: string
{
    case ValidationFailed = 'VALIDATION.FAILED';
    case NotFound = 'RESOURCE.NOT_FOUND';
    case Unauthenticated = 'AUTH.UNAUTHENTICATED';
    case InvalidCredentials = 'AUTH.INVALID_CREDENTIALS';
    case AccountInactive = 'AUTH.ACCOUNT_INACTIVE';
    case PermissionDenied = 'PERMISSION.DENIED';
    case RateLimitExceeded = 'RATE_LIMIT.EXCEEDED';
    case MethodNotAllowed = 'REQUEST.METHOD_NOT_ALLOWED';
    case ServerError = 'SERVER.ERROR';
    case TenantNotInitialized = 'TENANT.NOT_INITIALIZED';
    case TenantNotResolved = 'TENANT.NOT_RESOLVED';
    case TenantResolutionConflict = 'TENANT.RESOLUTION_CONFLICT';
    case EntitlementNotAvailable = 'ENTITLEMENT.NOT_AVAILABLE';
    case RegistrationNotRetryable = 'REGISTRATION.NOT_RETRYABLE';

    /*
     * Booking. Each of these is a DIFFERENT thing for a client to do about it,
     * which is the only reason they are separate codes: a taken slot is worth
     * retrying, a closed branch is not, and a policy refusal needs a different
     * sentence entirely (docs/10-API-FOUNDATION.md §4.1).
     */
    case BookingSlotUnavailable = 'BOOKING.SLOT_UNAVAILABLE';
    case BookingOutsideHours = 'BOOKING.OUTSIDE_HOURS';
    case BookingEmployeeUnavailable = 'BOOKING.EMPLOYEE_UNAVAILABLE';
    case BookingPolicyViolation = 'BOOKING.POLICY_VIOLATION';
    case BookingInvalidTransition = 'BOOKING.INVALID_TRANSITION';

    /** Same key, different payload. The client has a bug (§6). */
    case IdempotencyConflict = 'IDEMPOTENCY.CONFLICT';

    /*
     * Service Journey — the operational side of a visit. Distinct from the
     * BOOKING codes because they mean different things to a client: a booking
     * refusal is about the plan, a journey refusal is about the floor. A host
     * screen that could not tell them apart would show a customer-facing
     * message about a room being occupied (Phase 7 §46).
     */
    case JourneyInvalidTransition = 'JOURNEY.INVALID_TRANSITION';
    case JourneyPolicyViolation = 'JOURNEY.POLICY_VIOLATION';
    case JourneyResourceUnavailable = 'JOURNEY.RESOURCE_UNAVAILABLE';

    /*
     * Queue — waiting, calling and routing. Distinct again, and for the same
     * reason: "that ticket has already been served" and "that room is busy"
     * are different problems with different answers at the desk
     * (docs/17-QUEUE.md §10).
     */
    case QueueInvalidTransition = 'QUEUE.INVALID_TRANSITION';
    case QueuePolicyViolation = 'QUEUE.POLICY_VIOLATION';

    /*
     * Sales — the commercial transaction. "That sale is already finalized" and
     * "that discount would make the total negative" need different answers at
     * the till (docs/18-SALES.md).
     */
    case SalesInvalidTransition = 'SALES.INVALID_TRANSITION';
    case SalesPolicyViolation = 'SALES.POLICY_VIOLATION';

    public function httpStatus(): int
    {
        return match ($this) {
            self::ValidationFailed => 422,
            self::NotFound => 404,
            self::Unauthenticated, self::InvalidCredentials, self::AccountInactive => 401,
            self::PermissionDenied => 403,
            self::RateLimitExceeded => 429,
            self::MethodNotAllowed => 405,
            // A host that is not a tenant is indistinguishable from one that
            // does not exist: anything more precise confirms which centers are
            // on the platform.
            self::TenantNotResolved => 404,
            self::TenantResolutionConflict, self::EntitlementNotAvailable => 403,
            // The registration exists and the caller may see it; its current
            // state is what refuses the request. 409, not 403.
            self::RegistrationNotRetryable => 409,

            // 409 for the two that are conflicts with the CURRENT state of the
            // world — somebody else took the time, the appointment is already
            // completed — and 422 for the two that are wrong requests: a
            // closed branch and a rule violation would be refused just as
            // firmly on a retry.
            self::BookingSlotUnavailable,
            self::BookingEmployeeUnavailable,
            self::BookingInvalidTransition,
            self::IdempotencyConflict => 409,

            self::BookingOutsideHours, self::BookingPolicyViolation => 422,

            // Same split: a stage somebody else already started and a room
            // already in use are conflicts with the world as it is; an invalid
            // transition is a wrong request that a retry would not fix.
            self::JourneyResourceUnavailable => 409,
            self::JourneyInvalidTransition, self::JourneyPolicyViolation => 422,

            // A queue refusal is always a wrong request about a ticket whose
            // state the caller can read: calling a completed ticket or holding
            // one that is being served would be refused just as firmly on a
            // retry.
            self::QueueInvalidTransition, self::QueuePolicyViolation => 422,

            // Same reasoning: editing a finalized sale or finalizing an empty
            // one is refused just as firmly on a retry.
            self::SalesInvalidTransition, self::SalesPolicyViolation => 422,
            self::ServerError, self::TenantNotInitialized => 500,
        };
    }

    /**
     * Translation key for the display message. Falls back to the key itself
     * until the localisation files land in Phase 4.
     */
    public function translationKey(): string
    {
        return 'errors.'.$this->value;
    }
}
