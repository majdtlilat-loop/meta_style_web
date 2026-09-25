<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Reporting\ReportConnection;
use App\Modules\Catalog\Contracts\ReportServiceCategories;

final readonly class SqlReportServiceCategories implements ReportServiceCategories
{
    public function __construct(private ReportConnection $connections) {}

    public function forServices(array $serviceIds, ReadTarget $target): array
    {
        $serviceIds = array_values(array_unique(array_filter($serviceIds, static fn (int $id): bool => $id > 0)));

        if ($serviceIds === []) {
            return [];
        }

        // Names in the viewer's language; grouping happens in the caller, on ids.
        $rows = $this->connections->for($target)->table('services')
            ->leftJoin('service_categories as categories', 'categories.id', '=', 'services.service_category_id')
            ->whereIn('services.id', $serviceIds)
            ->get(['services.id', 'categories.id as category_id', 'categories.name as category_name']);

        $map = array_fill_keys($serviceIds, null);

        foreach ($rows as $row) {
            $map[(int) $row->id] = $row->category_id !== null
                ? ['id' => (int) $row->category_id, 'name' => self::translated($row->category_name)]
                : null;
        }

        return $map;
    }

    private static function translated(mixed $json): string
    {
        $values = json_decode((string) $json, true);
        $values = is_array($values) ? $values : [];

        return (string) ($values[app()->getLocale()] ?? $values['en'] ?? reset($values) ?: '—');
    }
}
