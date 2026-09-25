<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\AvailabilitySlot;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * "When can I come in?" — answered by the Booking Engine, never by RAYAN.
 *
 * ## The rule this tool exists to obey
 *
 * `BookingEngine::availability(..., publicChannel: true)`. The assistant does
 * not know what a working hour is, what an employee's availability block means,
 * how a multi-service visit is laid out, or that a room has capacity — and it
 * must not learn, because a second implementation of any of that is a second
 * answer to "is this stylist free", and the two would disagree on exactly the
 * afternoon it mattered (docs/27-RAYAN.md §4, CLAUDE.md "Booking").
 *
 * There is also NO CACHING here. A cached slot list is a promise about the
 * future, and the engine's own contract is that availability is ADVISORY even
 * when it is a millisecond old — a slot returned here can still be refused by
 * `create_booking`, and that is correct rather than a race.
 *
 * ## `publicChannel: true`
 *
 * The assistant talks to customers, so it sees what a customer sees: public
 * branches, online-bookable services. Passing `false` would let it offer a slot
 * on a service the center deliberately keeps off its public menu (§29).
 */
final class GetAvailableSlotsTool implements ToolHandler
{
    /**
     * A week. Long enough for "next Tuesday", short enough that the engine's
     * search stays bounded and the tool result stays inside a context window.
     */
    private const MAX_DAYS = 7;

    /** Enough times to offer; more is a wall of numbers nobody reads. */
    private const MAX_SLOTS = 12;

    public function __construct(private readonly BookingEngine $engine) {}

    public function tool(): RayanTool
    {
        return RayanTool::GetAvailableSlots;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'Find bookable start times for a service at a branch, on a given date. '
                .'Always call this before create_booking; never guess a time.',
            properties: [
                'branch_id' => ['type' => 'string', 'description' => 'The branch id from list_branches.'],
                'service_id' => ['type' => 'string', 'description' => 'The service id from list_services.'],
                'date' => [
                    'type' => 'string',
                    'description' => 'The local date at the branch, as YYYY-MM-DD.',
                ],
            ],
            required: ['branch_id', 'service_id', 'date'],
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $branchUuid = (string) $arguments['branch_id'];
        $date = (string) $arguments['date'];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return ToolResult::refused('invalid_arguments', 'I need a date in YYYY-MM-DD form.');
        }

        /** @var Branch|null $branch */
        $branch = Branch::query()->publiclyVisible()->where('uuid', $branchUuid)->first();

        if (! $branch instanceof Branch) {
            return ToolResult::refused('not_found', 'I could not find that branch.');
        }

        try {
            $slots = $this->engine->availability(
                new AvailabilityQuery(
                    branchUuid: $branchUuid,
                    lines: [new BookingLine(serviceUuid: (string) $arguments['service_id'])],
                    fromDate: $date,
                    toDate: $date,
                ),
                // The customer's view of the catalog. Never the staff one.
                publicChannel: true,
            );
        } catch (BookingFailed $e) {
            /*
             * The engine's own refusal, passed through as the model's answer.
             * Its messages are already written for customers — "that date is
             * too far ahead to book" — and rewording them here would create a
             * second vocabulary for the same rules.
             */
            return ToolResult::refused('unavailable', $e->getMessage());
        }

        $slots = array_slice($slots, 0, self::MAX_SLOTS);

        return ToolResult::ok([
            'date' => $date,
            'timezone' => $branch->timezone,
            'slots' => array_map(static fn (AvailabilitySlot $slot): array => [
                // The wall clock the customer will be told, and the absolute
                // instant `create_booking` must echo back. Both, because a
                // model asked to add an hour to "14:00" would do arithmetic
                // nobody wants it doing (docs/10-API-FOUNDATION.md §8).
                'time' => $slot->localTime,
                'starts_at' => $slot->startsAt->toIso8601String(),
            ], $slots),
            // Stated plainly, so the model says "nothing that day, shall I
            // look at Thursday?" rather than inventing a time.
            'available' => $slots !== [],
        ], resolvedBranchId: (int) $branch->getKey());
    }

    /**
     * How far ahead this tool will look, for the run loop's own sanity checks.
     */
    public static function maxDays(): int
    {
        return self::MAX_DAYS;
    }
}
