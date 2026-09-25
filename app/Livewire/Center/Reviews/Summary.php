<?php

declare(strict_types=1);

namespace App\Livewire\Center\Reviews;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Reviews\Concerns\BuildsReviewCards;
use App\Livewire\Center\Reviews\Concerns\ReviewFilterOptions;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Reviews\Application\RatingSummary;
use App\Modules\Reviews\Application\ReviewDays;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The rating picture for the branch and days chosen on the Reviews page: the
 * average, the 1–5 spread, and the averages per service and per employee.
 *
 * Every number comes from `RatingSummary` — the ONE place an average exists —
 * in one grouped query per answer, within the viewer's branch scope. Hidden
 * reviews are out, flagged ones still count (docs/22 §§8, 24).
 */
final class Summary extends Component
{
    use BuildsReviewCards;
    use ReviewFilterOptions;

    public const TOP = 8;

    #[Locked]
    public string $branch = '';

    #[Locked]
    public string $from = '';

    #[Locked]
    public string $to = '';

    public function render(RatingSummary $summary): View
    {
        $user = auth()->user();

        // Authorised on its own, not only by the page that embeds it.
        if (! $user instanceof User || ! $user->hasPermission(Permission::ReviewView)) {
            abort(403);
        }

        $scope = $user->branchScope();
        $branchId = $this->idFor($this->branchOptions($user), $this->branch, Branch::class);
        $days = ReviewDays::between($this->from === '' ? null : $this->from, $this->to === '' ? null : $this->to);

        $overall = $summary->overall($scope, $branchId, null, null, $days);
        $peak = max(1, ...array_values($overall['distribution']));

        return view('livewire.center.reviews.summary', [
            'count' => $overall['count'],
            'average' => $overall['average'],
            'stars' => self::stars((int) round((float) $overall['average'])),
            'distribution' => array_map(
                static fn (int $value, int $count): array => [
                    'value' => $value,
                    'count' => $count,
                    'share' => $overall['count'] === 0 ? 0 : (int) round($count / $overall['count'] * 100),
                    'width' => (int) round($count / $peak * 100),
                ],
                array_reverse(array_keys($overall['distribution'])),
                array_reverse(array_values($overall['distribution'])),
            ),
            'services' => $this->ranked($summary->byService($scope, $branchId, null, null, $days), Service::class),
            'employees' => $this->ranked($summary->byEmployee($scope, $branchId, null, null, $days), Employee::class),
        ]);
    }

    /**
     * The most-rated targets first, named in ONE query.
     *
     * @param  array<int, array{count: int, average: float}>  $rows
     * @param  class-string<Service|Employee>  $model
     * @return list<array{name: string, count: int, average: float, width: int}>
     */
    private function ranked(array $rows, string $model): array
    {
        uasort($rows, static fn (array $a, array $b): int => [$b['count'], $b['average']] <=> [$a['count'], $a['average']]);
        $rows = array_slice($rows, 0, self::TOP, true);

        if ($rows === []) {
            return [];
        }

        $names = $model::query()->whereIn('id', array_keys($rows))->get()
            ->mapWithKeys(fn (Model $row): array => [(int) $row->getKey() => (string) $row->getAttribute('name')?->get()])
            ->all();

        $ranked = [];

        foreach ($rows as $id => $row) {
            $ranked[] = [
                'name' => $names[$id] ?? '—',
                'count' => $row['count'],
                'average' => $row['average'],
                'width' => (int) round($row['average'] / 5 * 100),
            ];
        }

        return $ranked;
    }
}
