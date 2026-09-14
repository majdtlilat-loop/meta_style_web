<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Exceptions;

use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiProblem;
use RuntimeException;

/**
 * The Booking Engine refused, and this is why.
 *
 * ONE EXCEPTION CARRYING A CODE, rather than six sibling classes. Every caller
 * does the same two things with it — show the message and branch on the
 * code — and the alternative is a `catch` ladder that every new channel has to
 * remember to write out in full (docs/10-API-FOUNDATION.md §4.1).
 *
 * The named constructors are the vocabulary of the refusals a booking can hit.
 * Each maps to a stable machine-readable code clients already have in the API
 * catalog.
 *
 * MESSAGES ARE FOR A HUMAN AND MUST NOT LEAK. "Ahmed is already booked at 10:15
 * with Sara" would tell a guest on the public menu who is in the shop and when,
 * so the refusals below say that a time is unavailable and stop there
 * (docs/08-AUDIT-SECURITY.md §19).
 */
final class BookingFailed extends RuntimeException implements ApiProblem
{
    /**
     * @param  array<string, mixed>  $details
     */
    private function __construct(
        // NOT named `$code`: `Exception` already has one, it is an int, and
        // shadowing it with a typed readonly property is a fatal in PHP.
        private readonly ApiErrorCode $apiCode,
        string $message,
        /** @var array<string, mixed> */
        private readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function errorCode(): ApiErrorCode
    {
        return $this->apiCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function errorDetails(): array
    {
        return $this->details;
    }

    /**
     * Somebody else has the time, or the employee is busy.
     *
     * The one refusal a client should offer to retry: the availability it was
     * shown was true when it was computed and is not any more.
     */
    public static function slotUnavailable(string $message = 'That time is no longer available.'): self
    {
        return new self(ApiErrorCode::BookingSlotUnavailable, $message);
    }

    /**
     * The branch is shut, or the visit does not fit inside one open interval.
     *
     * Distinct from a taken slot because it is not going to become available by
     * waiting — the client should offer a different time, not a retry.
     */
    public static function outsideHours(string $message = 'The branch is not open for that time.'): self
    {
        return new self(ApiErrorCode::BookingOutsideHours, $message);
    }

    /**
     * No employee can do this: not eligible, not at this branch, not active, or
     * every eligible one is busy.
     */
    public static function employeeUnavailable(string $message = 'No one is available for that service at that time.'): self
    {
        return new self(ApiErrorCode::BookingEmployeeUnavailable, $message);
    }

    /**
     * A booking rule said no: too soon, too far ahead, service not bookable
     * online, cancellation window passed.
     *
     * @param  array<string, mixed>  $details
     */
    public static function policy(string $message, array $details = []): self
    {
        return new self(ApiErrorCode::BookingPolicyViolation, $message, $details);
    }

    /**
     * A status change that the lifecycle does not allow.
     *
     * @param  array<string, mixed>  $details
     */
    public static function invalidTransition(string $message, array $details = []): self
    {
        return new self(ApiErrorCode::BookingInvalidTransition, $message, $details);
    }
}
