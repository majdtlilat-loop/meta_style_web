<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * "What do you do, and what does it cost?"
 *
 * `publiclyVisible()` AND `is_online_bookable`, so the assistant can only offer
 * what a customer could have booked themselves from the menu. A service the
 * center sells at the desk but deliberately keeps off online booking stays off
 * it here too — otherwise the assistant would be a way around a decision the
 * center made on purpose (docs/27-RAYAN.md §10).
 *
 * Bounded. A center with four hundred services would otherwise produce a tool
 * result larger than the model's context, which fails the whole turn — so the
 * list is capped and the model is told, rather than silently truncated into
 * looking like the center's entire catalog.
 */
final class ListServicesTool implements ToolHandler
{
    /**
     * Enough for any real conversation. A customer choosing between more than
     * this is being sold to by a catalogue, not helped by an assistant.
     */
    private const LIMIT = 40;

    public function tool(): RayanTool
    {
        return RayanTool::ListServices;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'List the services customers can book online, with prices and durations. '
                .'Pass branch_id to see only what that branch offers.',
            properties: [
                'branch_id' => [
                    'type' => 'string',
                    'description' => 'The branch id from list_branches. Omit for all branches.',
                ],
            ],
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $query = Service::query()->publiclyVisible()->where('is_online_bookable', true);

        $branchUuid = is_string($arguments['branch_id'] ?? null) ? $arguments['branch_id'] : null;

        if ($branchUuid !== null) {
            /** @var Branch|null $branch */
            $branch = Branch::query()->publiclyVisible()->where('uuid', $branchUuid)->first();

            if (! $branch instanceof Branch) {
                // The same answer a branch that does not exist would give. A
                // non-public branch must not be distinguishable from a missing
                // one, or the tool becomes a way to enumerate them.
                return ToolResult::refused('not_found', 'I could not find that branch.');
            }

            $query->atBranch((int) $branch->getKey());
        }

        /** @var list<Service> $services */
        $services = $query->orderBy('sort_order')->orderBy('id')->limit(self::LIMIT + 1)->get()->all();

        $truncated = count($services) > self::LIMIT;
        $services = array_slice($services, 0, self::LIMIT);

        $currency = Currency::default();

        return ToolResult::ok([
            'services' => array_map(static fn (Service $service): array => [
                'id' => $service->uuid,
                'name' => $service->name->get(),
                'duration_minutes' => $service->duration_minutes,
                'price' => Money::fromMinor((int) $service->price_minor, $currency)->toMajorString(),
                'currency' => $currency->value,
            ], $services),
            // Stated, not hidden. A model that knows the list was cut asks the
            // customer to narrow it instead of implying that is everything.
            'truncated' => $truncated,
        ]);
    }
}
