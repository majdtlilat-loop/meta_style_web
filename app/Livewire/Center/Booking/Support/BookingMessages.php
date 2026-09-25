<?php

declare(strict_types=1);

namespace App\Livewire\Center\Booking\Support;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Security\Exceptions\MissingKeyVersion;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

/**
 * Turns an engine refusal into a sentence the desk can read, in its language.
 *
 * The Booking module speaks English to logs and to the API's `message`; the
 * API's `code` is what a client is meant to act on. This does the same for the
 * Manager screens: the error CODE picks the message, and the handful of policy
 * refusals a desk actually meets get their own wording. Anything unrecognised
 * falls back to the code's generic sentence — never to a raw English string in
 * an Arabic or Kurdish screen, and never to a stack trace.
 *
 * Presentation only. Nothing here decides whether something was allowed.
 */
final class BookingMessages
{
    /**
     * Policy refusals worth their own sentence, by the engine's message.
     *
     * @var array<string, string>
     */
    private const POLICY = [
        'That time is too soon to book.' => 'too_soon',
        'That date is too far ahead to book.' => 'too_far',
        'That date has already passed.' => 'date_passed',
        'That date range is too wide.' => 'range_too_wide',
        'The end of the range is before its start.' => 'range_inverted',
        'A booking needs at least one service.' => 'no_service',
        'A booking needs a customer.' => 'no_customer',
        'A booking needs a name.' => 'no_name',
        'That customer was not found.' => 'customer_missing',
        'That customer is archived.' => 'customer_archived',
        'We could not take that booking. Please contact the center.' => 'customer_archived',
        'That does not look like a phone number.' => 'phone_invalid',
        'That service is not available.' => 'service_unavailable',
        'That service is not offered at that branch.' => 'service_not_here',
        'That service has no duration configured and cannot be booked.' => 'service_no_duration',
        'That option is not available for this service.' => 'variation_unavailable',
        'One of the selected extras is not available for this service.' => 'addon_unavailable',
        'One of the resources you selected is not available at that branch.' => 'resource_not_here',
        'That branch is not available for booking.' => 'branch_unavailable',
        'That appointment cannot be moved.' => 'cannot_move',
        'That appointment has no services to move.' => 'cannot_move',
        'An appointment cannot be marked as a no-show before it starts. Cancel it instead.' => 'no_show_early',
        'That service is not part of this booking.' => 'not_found',
        'That service is no longer in the catalog, so its team member cannot be changed.' => 'service_removed',
        'That room or device is not part of this booking.' => 'not_found',
        'That room or device cannot be used for this service at this branch.' => 'room_unsuitable',
        'That room or device is not free at that time.' => 'room_busy',
    ];

    public static function for(Throwable $e): string
    {
        return match (true) {
            $e instanceof BookingFailed => self::booking($e),
            $e instanceof EntitlementRequired => __('manager_booking.errors.locked'),
            $e instanceof AuthorizationException => __('manager_booking.errors.forbidden'),
            $e instanceof ModelNotFoundException => __('manager_booking.errors.not_found'),
            $e instanceof MissingKeyVersion => __('manager_booking.errors.code_unavailable'),
            $e instanceof JourneyFailed => __('manager_booking.errors.visit'),
            default => __('manager_booking.errors.generic'),
        };
    }

    private static function booking(BookingFailed $e): string
    {
        $specific = self::POLICY[$e->getMessage()] ?? null;

        if ($specific !== null) {
            return __('manager_booking.errors.'.$specific);
        }

        return match ($e->errorCode()) {
            ApiErrorCode::BookingSlotUnavailable => __('manager_booking.errors.slot_unavailable'),
            ApiErrorCode::BookingOutsideHours => __('manager_booking.errors.outside_hours'),
            ApiErrorCode::BookingEmployeeUnavailable => __('manager_booking.errors.employee_unavailable'),
            ApiErrorCode::BookingInvalidTransition => __('manager_booking.errors.invalid_transition'),
            default => __('manager_booking.errors.policy'),
        };
    }
}
