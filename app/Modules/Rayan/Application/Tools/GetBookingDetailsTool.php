<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Rayan\Application\BookingAccess;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * One booking, in the shape a CUSTOMER is allowed to see.
 *
 * `AppointmentPresenter::forCustomer()` — the same allow-list the customer API
 * and the public booking response use, reused rather than reimplemented. That
 * is what keeps staff notes, the internal source code, who created the record,
 * the cancellation actor and internal ids out of a payload that is about to be
 * sent to an external model provider (docs/27-RAYAN.md §10).
 *
 * Reachable two ways: the resolved customer owns it, or the caller supplied its
 * reference and verification code. {@see BookingAccess} owns that rule, and
 * gives the same answer for every refusal.
 */
final class GetBookingDetailsTool implements ToolHandler
{
    public function __construct(
        private readonly BookingAccess $access,
        private readonly AppointmentPresenter $presenter,
    ) {}

    public function tool(): RayanTool
    {
        return RayanTool::GetBookingDetails;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'Look up one booking by its reference. '
                .'If the booking is not this customer\'s, a verification code is required.',
            properties: [
                'reference' => ['type' => 'string', 'description' => 'The booking reference, for example B-000412.'],
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
            // One answer for every failure: no such booking, somebody else's,
            // wrong code (docs/24-BOOKING-VERIFICATION.md §9).
            return ToolResult::refused('not_found', 'I could not find a booking with that reference.');
        }

        $appointment->load(['items.employee', 'items.addons', 'branch']);

        return ToolResult::ok(['booking' => $this->presenter->forCustomer($appointment)]);
    }
}
