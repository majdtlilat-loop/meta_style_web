<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\Service;

/**
 * Services a report may be filtered by, for the Manager report filters.
 *
 * Archived services are included — a report over last month must still be
 * able to ask about a service retired this week — and marked, so the picker
 * can say so. A uuid only: the report resolves it inside its own query.
 */
final class DashboardServiceOptions
{
    /**
     * @return list<array{uuid: string, name: string, archived: bool}>
     */
    public function all(): array
    {
        return Service::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'uuid', 'name', 'is_active', 'archived_at'])
            ->map(static fn (Service $service): array => [
                'uuid' => $service->uuid,
                'name' => $service->name->get(),
                'archived' => $service->archived_at !== null || ! $service->is_active,
            ])
            ->values()
            ->all();
    }
}
