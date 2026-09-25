<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Modules\Catalog\Domain\Models\ServiceCategory;

/**
 * Service categories a Standard report may be narrowed by.
 *
 * Archived and inactive categories are included — a report over last month
 * must still be able to ask about a category retired this week — and
 * marked. A uuid only: each report reader resolves it inside its own query.
 */
final class StandardReportCategoryOptions
{
    /**
     * @return list<array{uuid: string, name: string, archived: bool}>
     */
    public function all(): array
    {
        return ServiceCategory::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'uuid', 'name', 'is_active', 'archived_at'])
            ->map(static fn (ServiceCategory $category): array => [
                'uuid' => $category->uuid,
                'name' => $category->name->get(),
                'archived' => $category->archived_at !== null || ! $category->is_active,
            ])
            ->values()
            ->all();
    }
}
