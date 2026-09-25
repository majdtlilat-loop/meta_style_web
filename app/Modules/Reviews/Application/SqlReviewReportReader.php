<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Reviews\Contracts\ReviewReportReader;
use App\Modules\Reviews\Domain\Enums\RatingDimension;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;

final readonly class SqlReviewReportReader implements ReviewReportReader
{
    public function __construct(private ReportConnection $connections) {}

    /**
     * Visible reviews (submitted and flagged — hidden ones never count)
     * submitted in the period.
     *
     * Additive to the original shape: `daily` (reviews per branch-local day)
     * and `employees` / `services` ([id => {name, count, average}]) from the
     * rating rows anchored to the stage that was actually performed.
     */
    public function summary(ReportReadRequest $request): array
    {
        $connection = $this->connections->for($request->target);
        $visible = $connection->table('reviews')
            ->whereIn('status', ['submitted', 'flagged']);
        $request->applyWindow($visible, 'branch_id', 'submitted_at');

        $aggregate = (clone $visible)->selectRaw('COUNT(*) as review_count, AVG(overall_rating) as average')->first();
        $distribution = (clone $visible)->selectRaw('overall_rating, COUNT(*) as rating_count')
            ->groupBy('overall_rating')->pluck('rating_count', 'overall_rating')
            ->map(static fn (mixed $count): int => (int) $count)->all();

        $timezones = [];

        foreach ($request->windows as $window) {
            $timezones[$window->branchId] = $window->timezone;
        }

        $ids = [];
        $daily = [];

        foreach ((clone $visible)->select(['id', 'branch_id', 'submitted_at'])->orderBy('submitted_at')->cursor() as $row) {
            $ids[] = (int) $row->id;
            $date = CarbonImmutable::parse((string) $row->submitted_at, 'UTC')
                ->setTimezone($timezones[(int) $row->branch_id] ?? 'UTC')
                ->toDateString();
            $daily[$date] = ($daily[$date] ?? 0) + 1;
        }

        ksort($daily);
        $dimensions = [];

        if ($ids !== []) {
            $dimensions = $connection->table('review_ratings')
                ->whereIn('review_id', $ids)
                ->selectRaw('dimension, COUNT(*) as sample, AVG(rating) as average')
                ->groupBy('dimension')->get()->mapWithKeys(static fn (object $row): array => [
                    (string) $row->dimension => [
                        'sample' => (int) $row->sample,
                        'average' => round((float) $row->average, 1),
                    ],
                ])->all();
        }

        return [
            'count' => (int) ($aggregate->review_count ?? 0),
            'average' => $aggregate?->average !== null ? round((float) $aggregate->average, 1) : null,
            'distribution' => [
                1 => $distribution[1] ?? 0,
                2 => $distribution[2] ?? 0,
                3 => $distribution[3] ?? 0,
                4 => $distribution[4] ?? 0,
                5 => $distribution[5] ?? 0,
            ],
            'dimensions' => $dimensions,
            'daily' => $daily,
            'employees' => $ids === [] ? [] : $this->byTarget($connection, $ids, RatingDimension::Employee, 'employee_id', 'employees'),
            'services' => $ids === [] ? [] : $this->byTarget($connection, $ids, RatingDimension::Service, 'service_id', 'services'),
        ];
    }

    /**
     * One grouped query per dimension, names in a second keyed read.
     *
     * @param  list<int>  $reviewIds
     * @return array<int, array{name: string, count: int, average: float}>
     */
    private function byTarget(Connection $connection, array $reviewIds, RatingDimension $dimension, string $column, string $table): array
    {
        $rows = $connection->table('review_ratings')
            ->whereIn('review_id', $reviewIds)
            ->where('dimension', $dimension->value)
            ->whereNotNull($column)
            ->selectRaw($column.' as target, COUNT(*) as sample, AVG(rating) as average')
            ->groupBy($column)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = $connection->table($table)
            ->whereIn('id', $rows->pluck('target')->map(static fn (mixed $id): int => (int) $id)->all())
            ->pluck('name', 'id')
            ->all();
        $targets = [];

        foreach ($rows as $row) {
            $targets[(int) $row->target] = [
                'name' => self::translated($names[(int) $row->target] ?? null),
                'count' => (int) $row->sample,
                'average' => round((float) $row->average, RatingSummary::PRECISION),
            ];
        }

        return $targets;
    }

    private static function translated(mixed $json): string
    {
        $values = json_decode((string) $json, true);
        $values = is_array($values) ? $values : [];

        return (string) ($values[app()->getLocale()] ?? $values['en'] ?? reset($values) ?: '—');
    }
}
