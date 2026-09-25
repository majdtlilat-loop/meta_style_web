<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Modules\Rayan\Application\CustomerContext;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * "What do I have with you?" — for the RESOLVED customer only.
 *
 * Takes no arguments at all, and that is the design rather than an omission.
 * There is no `customer_id`, no `phone`, no `name` to pass, so a model that is
 * told "look up Sara's account" has nothing to put anywhere. The subject is
 * whoever the signature-verified phone number resolved to, and the registry has
 * already refused this tool for a conversation that resolved to nobody
 * (docs/27-RAYAN.md §§11–12).
 *
 * The payload is {@see CustomerContext}'s narrow allow-list: name, next
 * bookings, active memberships and packages, points. No contact details, no
 * notes, no money, no ids.
 */
final class GetCustomerContextTool implements ToolHandler
{
    public function __construct(private readonly CustomerContext $context) {}

    public function tool(): RayanTool
    {
        return RayanTool::GetCustomerContext;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'Get a summary of this customer: their name, upcoming bookings, '
                .'active memberships and packages, and loyalty points.',
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $summary = $this->context->for((int) $context->customerId);

        if ($summary === null) {
            /*
             * Resolved a moment ago, gone now — archived between the message
             * arriving and this tool running. Refused rather than answered from
             * a stale read, and in words that do not confirm anything about
             * whether that person exists here.
             */
            return ToolResult::refused('not_identified', 'I do not have a customer record for this number.');
        }

        return ToolResult::ok(['customer' => $summary]);
    }
}
