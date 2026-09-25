<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application\Tools;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Rayan\Contracts\ToolHandler;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolDefinition;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * One service in full: description, variations, add-ons.
 *
 * Same visibility rule as the list it came from, re-checked. A tool must never
 * assume the model only passes ids the previous tool returned — the model is
 * outside the trust boundary, and an id it invented or remembered from another
 * conversation has to be refused here (docs/27-RAYAN.md §12).
 *
 * A variation with a null price or duration INHERITS from its service and keeps
 * inheriting; that is a catalog rule, and resolving it to a number here would
 * be a second implementation of it. So the inherited value is read through the
 * service, exactly as the menu does.
 */
final class GetServiceDetailsTool implements ToolHandler
{
    public function tool(): RayanTool
    {
        return RayanTool::GetServiceDetails;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            tool: $this->tool(),
            description: 'Get the full details of one service: what it includes, its variations and its add-ons.',
            properties: [
                'service_id' => ['type' => 'string', 'description' => 'The service id from list_services.'],
            ],
            required: ['service_id'],
        );
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        /** @var Service|null $service */
        $service = Service::query()
            ->publiclyVisible()
            ->where('is_online_bookable', true)
            ->where('uuid', (string) $arguments['service_id'])
            ->with(['variations', 'addons'])
            ->first();

        if (! $service instanceof Service) {
            return ToolResult::refused('not_found', 'I could not find that service.');
        }

        $currency = Currency::default();

        return ToolResult::ok([
            'service' => [
                'id' => $service->uuid,
                'name' => $service->name->get(),
                'description' => $service->description?->get(),
                'duration_minutes' => $service->duration_minutes,
                'price' => Money::fromMinor((int) $service->price_minor, $currency)->toMajorString(),
                'currency' => $currency->value,
                'variations' => $service->variations
                    ->map(static fn (ServiceVariation $variation): array => [
                        'id' => $variation->uuid,
                        'name' => $variation->name->get(),
                        // Null means "same as the service", and it is passed
                        // through as null rather than resolved — the catalog
                        // owns that rule (ADR on inheritance, docs/04).
                        'duration_minutes' => $variation->duration_minutes,
                        'price' => $variation->price_minor === null
                            ? null
                            : Money::fromMinor((int) $variation->price_minor, $currency)->toMajorString(),
                    ])->values()->all(),
                'addons' => $service->addons
                    ->map(static fn (ServiceAddon $addon): array => [
                        'id' => $addon->uuid,
                        'name' => $addon->name->get(),
                        'duration_minutes' => $addon->duration_minutes,
                        'price' => Money::fromMinor((int) $addon->price_minor, $currency)->toMajorString(),
                    ])->values()->all(),
            ],
        ]);
    }
}
