<?php

declare(strict_types=1);

namespace App\Livewire\Center\Queue;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Security\Exceptions\TooManyAttempts;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Conversations\Domain\Exceptions\ConversationFailed;
use App\Modules\Queue\Domain\Exceptions\QueueFailed;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One place where an operational refusal becomes a message a desk can read —
 * in the desk's language.
 *
 * The Actions refuse in plain English written for a reception desk. Those are
 * the rules talking, so they are never softened into something vaguer; but an
 * Arabic or Kurdish host deserves them in Arabic or Kurdish. Known refusals map
 * to `manager_queue.errors.*`; a transition refused because somebody else got
 * there first ("A ticket that is completed cannot…") is the same answer every
 * time: the screen was stale, and it has been refreshed.
 *
 * `EntitlementRequired` used to escape these screens as a Livewire 500. It is
 * an answer, not an error: the plan does not include it.
 *
 * Anything unknown shows the Action's own words in English and a generic line
 * in the other interface languages, never a raw key.
 */
final class OperationalFailure
{
    /**
     * The exact refusals the Queue, Journey and Conversations Actions raise.
     *
     * @var array<string, string>
     */
    private const KNOWN = [
        'A display needs a name, so staff can tell the screens apart.' => 'display_name',
        'A display shows a department or a service point, not both.' => 'display_scope',
        'A queue priority must be between 0 and 255.' => 'priority_range',
        'A reply cannot be empty.' => 'reply_empty',
        'A service point needs a name.' => 'point_name',
        'A transfer needs a destination.' => 'transfer_destination',
        'A visit needs at least one service.' => 'services_required',
        'A walk-in needs a name.' => 'name_required',
        'Another service point at this branch already uses that code.' => 'code_taken',
        'A display code must be one to eight letters, digits or dashes — it is read from across a room.' => 'code_format',
        'A ticket prefix must be one to four letters or digits, with no spaces.' => 'prefix_format',
        'One of those services is not available.' => 'service_unavailable',
        'Say why this service was skipped.' => 'skip_reason',
        'That appointment has no services, so there is nothing to start.' => 'no_services',
        'That appointment is already finished or cancelled, so nobody can be checked in against it.' => 'check_in_closed',
        'That branch was not found.' => 'branch_missing',
        'That conversation cannot be changed from its current state.' => 'state_changed',
        'That conversation is closed.' => 'conversation_closed',
        'That conversation is no longer available.' => 'not_found',
        'That department was not found.' => 'department_missing',
        'That is not a service in this visit.' => 'not_found',
        'That is the resource already in use.' => 'same_resource',
        'That language is not one this platform knows.' => 'language',
        'That note does not belong to this service.' => 'not_found',
        'That reply is too long.' => 'reply_long',
        'That resource is no longer in service.' => 'resource_retired',
        'That resource is not available at this branch.' => 'resource_branch',
        'That service does not belong to a visit.' => 'not_found',
        'That service is already finished.' => 'stage_finished',
        'That service is already finished, so its employee cannot be changed.' => 'stage_finished',
        'That service is already finished, so its resources cannot be changed.' => 'stage_finished',
        'That service is already finished, so there is nobody to call.' => 'stage_finished',
        'That service is not offered at this branch.' => 'service_branch',
        'That service point is not available at this branch.' => 'point_branch',
        'That team member does not exist.' => 'employee_missing',
        'That team member does not work at this branch.' => 'employee_branch',
        'That team member is not active.' => 'employee_inactive',
        'That team member is not qualified for one of those services.' => 'employee_unqualified',
        'That team member is not qualified for this service.' => 'employee_unqualified',
        'That ticket could not be issued. Please try again.' => 'retry',
        'That ticket is not attached to a service.' => 'not_found',
        'That ticket is not on hold.' => 'state_changed',
        'That visit could not be started. Please try again.' => 'retry',
        'That visit has nothing waiting to be called.' => 'nothing_waiting',
        'That visit is not attached to a branch.' => 'branch_missing',
        'This service is not using that resource.' => 'state_changed',
        'Finish or skip every service before closing the visit.' => 'settle_first',
        'Only a ticket that has been called can be skipped.' => 'skip_uncalled',
        'That customer is already being served.' => 'already_serving',
        'That customer is already being served. End the visit instead.' => 'already_serving',
        'That customer is already being served. Hand the work on instead.' => 'already_serving',
        'The assistant is not available for this center.' => 'assistant_unavailable',
        'A note needs some text.' => 'note_empty',
        'Choose at least two languages to rotate.' => 'rotation_languages',
        'That language is not switched on for this center.' => 'rotation_language',
        'That item is no longer on this screen.' => 'media_gone',
    ];

    /**
     * Refusals whose wording carries a state: somebody else moved the record.
     *
     * @var list<string>
     */
    private const STALE = [
        '/^A ticket that is \w+ (cannot|can no longer)/',
        '/^A stage that is \w+ cannot be marked/',
        '/^A visit that is \w+ cannot be/',
        '/^That service is (already )?\w+(, so it cannot be finished)?\.$/',
    ];

    public static function message(Throwable $failure): string
    {
        if ($failure instanceof EntitlementRequired) {
            return __('manager_queue.errors.locked');
        }

        if ($failure instanceof ModelNotFoundException) {
            return __('manager_queue.errors.not_found');
        }

        if ($failure instanceof TooManyAttempts) {
            return __('manager_queue.errors.too_many');
        }

        $message = $failure instanceof ValidationException
            ? (string) $failure->validator->errors()->first()
            : $failure->getMessage();

        if ($failure instanceof AuthorizationException) {
            return str_contains($message, 'branch')
                ? __('manager_queue.errors.branch')
                : (str_contains($message, 'not yours') ? __('manager_queue.errors.not_yours') : __('manager_queue.errors.forbidden'));
        }

        if (isset(self::KNOWN[$message])) {
            return __('manager_queue.errors.'.self::KNOWN[$message]);
        }

        foreach (self::STALE as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return __('manager_queue.errors.state_changed');
            }
        }

        return app()->getLocale() === 'en' && $message !== '' ? $message : __('manager_queue.errors.generic');
    }

    /**
     * Whether this is a refusal a screen reports (rather than a bug to raise).
     */
    public static function handles(Throwable $failure): bool
    {
        return $failure instanceof QueueFailed
            || $failure instanceof JourneyFailed
            || $failure instanceof ConversationFailed
            || $failure instanceof BookingFailed
            || $failure instanceof AuthorizationException
            || $failure instanceof EntitlementRequired
            || $failure instanceof ModelNotFoundException
            || $failure instanceof ValidationException
            || $failure instanceof TooManyAttempts;
    }
}
