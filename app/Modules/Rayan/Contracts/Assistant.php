<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Contracts;

use App\Modules\Rayan\Domain\Data\AssistantReply;
use App\Modules\Rayan\Domain\Data\AssistantRequest;

/**
 * THE assistant contract. One method, and the boundary it draws is the point.
 *
 * ```
 *   Conversations  ──calls──►  Assistant  ──calls──►  BookingEngine, Catalog…
 * ```
 *
 * RAYAN takes a request of PRIMITIVES and returns a reply of primitives. It
 * never receives a `Conversation`, never receives a `Message`, and therefore
 * cannot write either — which is what makes "the assistant does not own
 * conversation state" a structural fact rather than a convention somebody has
 * to remember (docs/27-RAYAN.md §4).
 *
 * It also runs the dependency the right way round. The channel sits ABOVE the
 * assistant and calls down into it; the assistant sits above the domain modules
 * and calls down into them. Nothing below ever points back up, so an
 * architecture test can say plainly that Booking, Customers, Sales, Payments
 * and Finance do not know RAYAN or Conversations exist (§21).
 *
 * ## What an implementation must guarantee
 *
 * - it NEVER sends a message. It returns text; the channel delivers it.
 * - it NEVER writes to a domain table. Every change goes through an approved
 *   Action, which re-checks entitlement, permission, ownership and branch.
 * - it NEVER trusts the model for identity. Tenant, customer and verified phone
 *   come from {@see AssistantRequest} and are not tool parameters (§11).
 * - it ALWAYS terminates. Bounded turns, bounded tool calls, bounded tokens,
 *   bounded wall clock (§15).
 * - it NEVER throws for an ordinary failure. A provider outage, a refusal, a
 *   timeout and an exhausted quota all come back as
 *   {@see AssistantReply::handOff()}, because a customer's message must survive
 *   whatever went wrong and reach a person (§16).
 */
interface Assistant
{
    public function answer(AssistantRequest $request): AssistantReply;
}
