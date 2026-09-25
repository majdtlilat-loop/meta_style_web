<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * "Where are you?" — the center's PUBLICLY VISIBLE branches.
 *
 * `publiclyVisible()`, the same scope the guest menu uses. A branch a center
 * keeps off its public page — a back office, a training room, a location not
 * open yet — must never be offered by the assistant, because a model asked
 * "what other branches do you have?" reads out whatever it was handed
 * (docs/27-RAYAN.md §10).
 *
 * The payload is named field by field. No ids, no internal flags, no phone
 * numbers of staff: what a customer would see on the menu page, and nothing
 * else. `uuid` travels because the booking tools need something to refer to a
 * branch BY, and a uuid is opaque — it identifies without revealing how many
 * branches exist or in what order they were created.
 */
final class ListBranchesTool implements ToolHandler
{
    public function tool(): RayanTool
    {
        return RayanTool::ListBranches;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'List the branches of this center that customers can visit and book at. '
                .'Call this first when the customer has not said which branch they mean.',
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        /** @var list<Branch> $branches */
        $branches = Branch::query()->publiclyVisible()->orderByDesc('is_main')->orderBy('id')->get()->all();

        return ToolResult::ok([
            'branches' => array_map(static fn (Branch $branch): array => [
                'id' => $branch->uuid,
                'name' => $branch->name->get(),
                'address' => $branch->address,
                'timezone' => $branch->timezone,
            ], $branches),
        ]);
    }
}
