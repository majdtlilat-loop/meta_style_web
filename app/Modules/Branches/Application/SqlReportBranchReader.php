<?php

declare(strict_types=1);

namespace App\Modules\Branches\Application;

use App\Kernel\Authorization\BranchScope;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Reporting\ReportConnection;
use App\Modules\Branches\Contracts\ReportBranchReader;

final readonly class SqlReportBranchReader implements ReportBranchReader
{
    public function __construct(private ReportConnection $connections) {}

    public function accessible(BranchScope $scope, array $selectedUuids, ReadTarget $target): array
    {
        $query = $this->connections->for($target)->table('branches')
            ->select(['id', 'uuid', 'name', 'timezone'])
            ->whereNull('archived_at')
            ->orderBy('sort_order')
            ->orderBy('id');

        $scope->applyTo($query, 'id');

        if ($selectedUuids !== []) {
            $query->whereIn('uuid', $selectedUuids);
        }

        return $query->get()->map(function (object $row): array {
            $names = json_decode((string) $row->name, true);
            $names = is_array($names) ? $names : [];
            $locale = app()->getLocale();

            return [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'name' => (string) ($names[$locale] ?? $names['en'] ?? reset($names) ?: 'Branch'),
                'timezone' => (string) $row->timezone,
            ];
        })->all();
    }
}
