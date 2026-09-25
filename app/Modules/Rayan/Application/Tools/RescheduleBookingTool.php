<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Rayan\Application\BookingAccess;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Moves a booking, through the Booking Engine's own reschedule Action.
 *
 * Same validation, same branch lock, same availability re-check and the same
 * audit entry as a receptionist dragging it on the calendar — because it IS
 * that Action. The assistant supplies a new instant and an actor; everything
 * about whether the move is allowed belongs to the engine
 * (docs/27-RAYAN.md §4).
 *
 * Authority comes from {@see BookingAccess}: the resolved customer owns it, or
 * a reference plus its verification code was supplied. A reference on its own
 * moves nothing.
 */
final class RescheduleBookingTool implements ToolHandler
{
    public function __construct(
        private readonly BookingEngine $engine,
        private readonly BookingAccess $access,
    ) {}

    public function tool(): RayanTool
    {
        return RayanTool::RescheduleBooking;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'Move an existing booking to a new time. '
                .'Only use a starts_at value returned by get_available_slots.',
            properties: [
                'reference' => ['type' => 'string', 'description' => 'The booking reference.'],
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'The exact starts_at value from get_available_slots.',
                ],
                'verification_code' => [
                    'type' => 'string',
                    'description' => 'The 10-character booking code, only if the customer supplied one.',
                ],
            ],
            required: ['reference', 'starts_at'],
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $code = is_string($arguments['verification_code'] ?? null) ? $arguments['verification_code'] : null;

        $appointment = $this->access->resolve((string) $arguments['reference'], $code, $context);

        if ($appointment === null) {
            return ToolResult::refused('not_found', 'I could not find a booking with that reference.');
        }

        try {
            $startsAt = CarbonImmutable::parse((string) $arguments['starts_at'])->utc();
        } catch (InvalidFormatException) {
            return ToolResult::refused(
                'invalid_arguments',
                'I need the exact starts_at value from get_available_slots.',
            );
        }

        try {
            $moved = $this->engine->reschedule($appointment, $startsAt, BookingActor::assistant());
        } catch (BookingFailed $e) {
            // The engine's own customer-facing refusal — "that time is too soon
            // to book", "that team member is no longer free at that time".
            return ToolResult::refused('unavailable', $e->getMessage());
        } catch (EntitlementRequired) {
            return ToolResult::refused('not_entitled', 'I cannot change bookings right now.');
        } catch (AuthorizationException) {
            return ToolResult::refused('not_allowed', 'I cannot move that booking.');
        }

        return ToolResult::ok([
            'booking' => [
                'reference' => $moved->reference,
                'date' => $moved->localDate(),
                'time' => $moved->localStart()->format('H:i'),
                'status' => $moved->status->value,
            ],
        ]);
    }
}
