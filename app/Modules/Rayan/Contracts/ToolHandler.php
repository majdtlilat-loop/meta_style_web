<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Contracts;

use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * One approved operation the assistant may request.
 *
 * ## The contract every handler is held to (docs/27-RAYAN.md §12)
 *
 * 1. RE-VALIDATE EVERYTHING. Entitlement, ownership, branch scope and booking
 *    state are checked HERE, in application code, on every call — never once at
 *    the start of a run and never in a prompt. A model that was talked into
 *    asking for something it should not have gets the same refusal it would
 *    have got on the first message.
 * 2. TAKE IDENTITY FROM THE CONTEXT, NEVER THE ARGUMENTS. The tenant, the
 *    customer and the verified phone are on {@see ToolContext}. No handler may
 *    read a customer id, a phone number or a tenant from `$arguments`, and no
 *    tool schema declares one — so there is nothing to read (§11).
 * 3. DELEGATE, NEVER REIMPLEMENT. Availability comes from the Booking Engine.
 *    Bookings are made by the Booking Engine. A handler that computed a slot
 *    would be a second opinion about whether a stylist is free (§4).
 * 4. RETURN AN ALLOW-LIST. Whatever is in {@see ToolResult::$payload} is
 *    serialised and sent to an external provider, and from there may be read
 *    back to a customer. It is named field by field, never a model's
 *    `toArray()` (§10).
 * 5. REFUSE AS DATA, NOT AS AN EXCEPTION. A refusal lets the model carry the
 *    conversation on sensibly; a throw ends the run and leaves the customer
 *    with nothing.
 */
interface ToolHandler
{
    public function tool(): RayanTool;

    /**
     * The name, description and argument schema — used BOTH to tell the
     * provider what exists and to validate what comes back, so the two can
     * never drift apart.
     */
    public function definition(): ToolDefinition;

    /**
     * @param  array<string, mixed>  $arguments  validated against the schema,
     *                                           and allow-listed to its
     *                                           declared properties
     */
    public function handle(array $arguments, ToolContext $context): ToolResult;
}
