<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;
use Carbon\CarbonImmutable;

/**
 * "What have I got booked?" — for the RESOLVED customer, and nobody else.
 *
 * The customer id comes from {@see ToolContext}, never from an argument, and
 * the registry has already refused this tool outright for a conversation that
 * has not been resolved to somebody (docs/27-RAYAN.md §§11–12). So there is no
 * parameter here that could name a person.
 *
 * Upcoming only, and bounded. "Everything I have ever booked" is not a question
 * a WhatsApp conversation answers well, and a customer with four years of
 * history would produce a tool result larger than the model's context.
 */
final class GetCustomerBookingsTool implements ToolHandler
{
    private const LIMIT = 10;

    public function tool(): RayanTool
    {
        return RayanTool::GetCustomerBookings;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'List this customer\'s upcoming bookings with their references.',
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        /** @var list<Appointment> $appointments */
        $appointments = Appointment::query()
            ->where('customer_id', $context->customerId)
            ->whereIn('status', [AppointmentStatus::Booked->value, AppointmentStatus::Confirmed->value])
            ->where('starts_at', '>=', CarbonImmutable::now()->utc())
            ->with('branch')
            ->orderBy('starts_at')
            ->limit(self::LIMIT)
            ->get()
            ->all();

        return ToolResult::ok([
            'bookings' => array_map(static fn (Appointment $appointment): array => [
                'reference' => $appointment->reference,
                'date' => $appointment->localDate(),
                'time' => $appointment->localStart()->format('H:i'),
                'branch' => $appointment->branch?->name->get(),
                'status' => $appointment->status->value,
            ], $appointments),
        ]);
    }
}
