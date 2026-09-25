<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Makes a real appointment, through the real Booking Engine.
 *
 * ## The identity rules, which are the whole point (docs/27-RAYAN.md §12)
 *
 * The phone number comes from {@see ToolContext::$verifiedPhone} — out of a
 * signature-verified Meta notification — and there is NO tool parameter for
 * one. A customer who writes "book this for my friend on +9647509999999"
 * produces a model that has nowhere to put that number, and a handler that
 * would ignore it if it did.
 *
 * `CustomerRef::details()` is used for both cases, deliberately:
 *
 *   KNOWN sender    the stored name is used, not one the model produced, so a
 *                   conversation cannot rename somebody. The engine's resolver
 *                   matches on the normalised phone and reuses the existing
 *                   record — one person, one record (ADR-041).
 *   FIRST-TIME      the model must have collected a name. The resolver creates
 *                   the customer, and it is the ONLY way this module ever
 *                   creates one: there is no customer-create tool, because one
 *                   would be a tool for writing arbitrary people into a
 *                   center's CRM (§12).
 *
 * No CustomerAccount is created. An account is a login, and messaging a salon
 * is not a request for one (§13).
 *
 * ## Everything else is the engine's
 *
 * Availability, the branch lock, the re-check under it, the policy window, the
 * price snapshots, the audit entry and the verification code all belong to
 * `BookingEngine::book()`. A slot this conversation was offered a minute ago
 * can still be refused here, and that refusal is passed back to the model as
 * an answer rather than an error — so it can offer another time instead of
 * leaving the customer with silence (§4).
 */
final class CreateBookingTool implements ToolHandler
{
    public function __construct(private readonly BookingEngine $engine) {}

    public function tool(): RayanTool
    {
        return RayanTool::CreateBooking;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'Book an appointment. Only use a starts_at value returned by get_available_slots — '
                .'never a time you worked out yourself. If you do not know the customer\'s name, ask for it first.',
            properties: [
                'branch_id' => ['type' => 'string', 'description' => 'The branch id from list_branches.'],
                'service_id' => ['type' => 'string', 'description' => 'The service id from list_services.'],
                'starts_at' => [
                    'type' => 'string',
                    'description' => 'The exact starts_at value from get_available_slots.',
                ],
                'customer_name' => [
                    'type' => 'string',
                    'description' => 'The customer\'s name, needed only when this is their first booking.',
                ],
            ],
            required: ['branch_id', 'service_id', 'starts_at'],
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $startsAt = $this->instant($arguments['starts_at'] ?? null);

        if ($startsAt === null) {
            return ToolResult::refused(
                'invalid_arguments',
                'I need the exact starts_at value from get_available_slots.',
            );
        }

        /** @var Branch|null $branch */
        $branch = Branch::query()->publiclyVisible()->where('uuid', (string) $arguments['branch_id'])->first();

        if (! $branch instanceof Branch) {
            return ToolResult::refused('not_found', 'I could not find that branch.');
        }

        $name = $this->name($arguments, $context);

        if ($name === null) {
            return ToolResult::refused('needs_name', 'I need the customer\'s name before I can book.');
        }

        try {
            $booked = $this->engine->book(
                new BookingRequest(
                    branchUuid: (string) $branch->uuid,
                    lines: [new BookingLine(serviceUuid: (string) $arguments['service_id'])],
                    startsAt: $startsAt,
                    /*
                     * The verified phone, never a model argument. This is the
                     * single most important line in the module (§11).
                     */
                    customer: CustomerRef::details($name, $context->verifiedPhone, null, $context->locale),
                ),
                // An AI actor, on the `rayan` channel. Never `staff`: nobody at
                // the center pressed anything, and saying otherwise would lie
                // to an investigation.
                BookingActor::assistant(),
            );
        } catch (BookingFailed $e) {
            // The engine's own customer-facing wording, passed straight through.
            return ToolResult::refused('unavailable', $e->getMessage());
        } catch (EntitlementRequired) {
            /*
             * The center stopped selling booking mid-conversation. Deliberately
             * NOT explained to the customer — a center's plan is not their
             * customer's business (§51).
             */
            return ToolResult::refused('not_entitled', 'I cannot make bookings right now.');
        } catch (AuthorizationException) {
            return ToolResult::refused('not_allowed', 'I cannot make that booking.');
        }

        $appointment = $booked->appointment;

        return ToolResult::ok(
            [
                'booking' => [
                    // The quotable reference. Public, and it authenticates
                    // nothing, which is why it is safe to read out (§1).
                    'reference' => $appointment->reference,
                    'date' => $appointment->localDate(),
                    'time' => $appointment->localStart()->format('H:i'),
                    'branch' => $branch->name->get(),
                    'status' => $appointment->status->value,
                ],
                /*
                 * THE VERIFICATION CODE IS NOT HERE, and its absence is
                 * deliberate.
                 *
                 * Every booking gets one; the WhatsApp channel does not send or
                 * echo it. So "a digest exists" must never be read as "the
                 * customer has the code" — they do not, and this is where that
                 * stops being true. A verified WhatsApp sender does not need
                 * one: their phone number is the evidence. If they later reach
                 * the center another way, they regenerate through an authorised
                 * flow (docs/24-BOOKING-VERIFICATION.md §12).
                 */
            ],
            // Carried back so the conversation now knows who it is talking to —
            // from what the ENGINE resolved, never from what the model said.
            resolvedCustomerId: (int) $appointment->customer_id,
            resolvedBranchId: (int) $branch->getKey(),
        );
    }

    /**
     * The name to book under.
     *
     * A known customer's stored name wins over anything the model produced, so
     * a conversation can never rename a person in the center's CRM.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function name(array $arguments, ToolContext $context): ?string
    {
        if ($context->customerId !== null) {
            /** @var Customer|null $customer */
            $customer = Customer::query()->whereKey($context->customerId)->first();

            if ($customer instanceof Customer) {
                return $customer->name;
            }
        }

        $supplied = $arguments['customer_name'] ?? null;

        return is_string($supplied) && trim($supplied) !== '' ? trim($supplied) : null;
    }

    /**
     * Parses the instant the slot tool handed out.
     *
     * ISO-8601 WITH AN OFFSET, echoed back rather than reconstructed. A model
     * doing timezone arithmetic on a wall clock is how a booking lands three
     * hours out, and a malformed value is refused rather than guessed at
     * (docs/10-API-FOUNDATION.md §8).
     */
    private function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
