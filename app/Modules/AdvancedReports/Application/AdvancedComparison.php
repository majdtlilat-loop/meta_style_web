<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Reports\Application\ReportsAccess;
use InvalidArgumentException;

/**
 * Side-by-side datasets for 2–4 entities of one kind over the same period:
 * branch vs branch, employee vs employee, service vs service.
 *
 * Every entity is read through the same reporting contracts as the rest of
 * Advanced Reports, from a request derived from the AUTHORIZED one:
 *
 *   branches   one of the request's own windows (never another branch);
 *   employees  the ACTUAL performer filter the readers resolve in SQL —
 *              booked items for bookings, performed stages for visits;
 *   services   the performed / reserved service filter, likewise.
 *
 * Only the facts a filter genuinely narrows are read for employees and
 * services: sales, payments and customers are not attributed to either, so
 * they are not compared (docs/28 §4 — no employee-attributed revenue).
 */
final readonly class AdvancedComparison
{
    public const MAX_ENTITIES = 4;

    /** Which sections each comparison reads. */
    public const MODES = [
        'branches' => ['bookings', 'visits', 'sales', 'payments', 'customers', 'queue', 'reviews'],
        'employees' => ['bookings', 'visits', 'queue'],
        'services' => ['bookings', 'visits', 'queue'],
    ];

    public function __construct(
        private ReportsAccess $access,
        private SectionFacts $facts,
    ) {}

    /**
     * @param  list<string>  $keys  branch, employee or service uuids, in the order chosen
     * @return array{mode: string, sections: list<string>, entities: list<array{key: string, name: string|null, facts: array<string, array<string, mixed>>}>}
     */
    public function compare(string $mode, User $user, ReportReadRequest $current, array $keys): array
    {
        $this->access->advanced($user);

        if (! isset(self::MODES[$mode])) {
            throw new InvalidArgumentException('Unknown comparison.');
        }

        $sections = $this->facts->permitted($user, self::MODES[$mode]);
        $entities = [];
        $keys = array_slice(array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== ''))), 0, self::MAX_ENTITIES);

        foreach ($sections === [] ? [] : $keys as $key) {
            $request = match ($mode) {
                'branches' => PeriodWindows::only($current, [$key]),
                'employees' => PeriodWindows::filtered($current, ['employee' => $key]),
                default => PeriodWindows::filtered($current, ['service' => $key]),
            };

            // A branch outside the authorized windows is not compared at all.
            if ($request->windows === []) {
                continue;
            }

            $facts = [];

            foreach ($sections as $section) {
                $facts[$section] = $this->facts->read($section, $request);
            }

            $entities[] = [
                'key' => $key,
                'name' => $mode === 'branches' ? $request->windows[0]->branchName : null,
                'facts' => $facts,
            ];
        }

        return ['mode' => $mode, 'sections' => $sections, 'entities' => $entities];
    }
}
