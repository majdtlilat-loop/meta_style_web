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
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Cancels a booking, through the Booking Engine's own lifecycle Action.
 *
 * The cancellation notice window, who may cancel, what cancelling does to the
 * visit and the audit entry all belong to the engine. In particular a customer
 * cancelling a visit that has ALREADY STARTED is a no-show rather than a
 * cancellation — a distinction the lifecycle already enforces, and one the
 * assistant must not be allowed its own opinion about (docs/27-RAYAN.md §4).
 *
 * ## The reason names the channel, never the customer's words
 *
 * `cancellation_reason` is rendered on staff screens and grouped in reports.
 * Copying whatever somebody typed into WhatsApp would put free-form customer
 * text — possibly abusive, possibly containing their own phone number — into a
 * structured operational field. The thread is where their words live (§10).
 */
final class CancelBookingTool implements ToolHandler
{
    public function __construct(
        private readonly BookingEngine $engine,
        private readonly BookingAccess $access,
    ) {}

    public function tool(): RayanTool
    {
        return RayanTool::CancelBooking;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'Cancel an existing booking. Always confirm with the customer before calling this.',
            properties: [
                'reference' => ['type' => 'string', 'description' => 'The booking reference.'],
                'verification_code' => [
                    'type' => 'string',
                    'description' => 'The 10-character booking code, only if the customer supplied one.',
                ],
            ],
            required: ['reference'],
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
            $cancelled = $this->engine->cancel(
                $appointment,
                BookingActor::assistant(),
                'Cancelled by the customer over WhatsApp.',
            );
        } catch (BookingFailed $e) {
            return ToolResult::refused('unavailable', $e->getMessage());
        } catch (EntitlementRequired) {
            return ToolResult::refused('not_entitled', 'I cannot change bookings right now.');
        } catch (AuthorizationException) {
            return ToolResult::refused('not_allowed', 'I cannot cancel that booking.');
        }

        return ToolResult::ok([
            'booking' => [
                'reference' => $cancelled->reference,
                'status' => $cancelled->status->value,
            ],
        ]);
    }
}
