<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReadTarget;
use App\Modules\AdvancedReports\Application\AdvancedReportCatalog;
use App\Modules\AdvancedReports\Application\AdvancedReports;
use App\Modules\AdvancedReports\Application\PeriodWindows;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\ReportsAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdvancedReportExportController extends Controller
{
    /**
     * `$center` is the center host's `{center}` segment, bound first; the
     * tenant is already resolved from it, so the value itself is not used.
     */
    public function __invoke(Request $request, string $center, string $report, ReportsAccess $access, AdvancedReportCatalog $catalog, ReportRequestFactory $requests, AdvancedReports $reports, Audit $audit): StreamedResponse
    {
        unset($center);
        abort_if($catalog->find($report) === null, 404);
        /** @var User $user */
        $user = $request->user();
        $access->advanced($user, Permission::ReportExport);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'uuid'],
            // The page's comparison period (dates only; the branches are always
            // the authorized request's). Absent: the equal preceding period.
            'compare_from' => ['nullable', 'required_with:compare_to', 'date_format:Y-m-d'],
            'compare_to' => ['nullable', 'required_with:compare_from', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
        ]);
        $read = $requests->make(
            $user->branchScope(),
            (string) $validated['from'],
            (string) $validated['to'],
            isset($validated['branch']) ? [(string) $validated['branch']] : [],
            ReadTarget::Reporting,
        );
        $comparison = isset($validated['compare_from'], $validated['compare_to'])
            ? PeriodWindows::shift($read, (string) $validated['compare_from'], (string) $validated['compare_to'])
            : null;
        $result = $reports->run($report, $user, $read, $comparison)->toArray();

        $audit->record(new AuditEvent(
            action: 'advanced_report.exported',
            category: AuditCategory::System,
            actor: Actor::staff($user),
            targetType: 'advanced_report',
            targetId: $report,
            targetLabel: (string) $result['title'],
            meta: ['from' => $read->fromDate, 'to' => $read->toDate, 'compare_from' => $comparison?->fromDate, 'compare_to' => $comparison?->toDate, 'as_of' => $result['as_of']],
        ));

        return response()->streamDownload(function () use ($result): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Advanced Report', $this->safe((string) $result['title'])]);
            fputcsv($output, ['As of', (string) $result['as_of']]);
            fputcsv($output, []);
            fputcsv($output, ['Metric', 'Value', 'Format']);

            foreach ($result['kpis'] as $kpi) {
                fputcsv($output, [$this->safe((string) $kpi['label']), $this->safe((string) ($kpi['value'] ?? '')), (string) $kpi['format']]);
            }

            if ($result['rows'] !== []) {
                fputcsv($output, []);
                $columns = array_keys($result['rows'][0]);
                fputcsv($output, $columns);

                foreach (array_slice($result['rows'], 0, (int) config('reports.export_max_rows', 100000)) as $row) {
                    fputcsv($output, array_map(function (mixed $value): string {
                        $text = is_scalar($value) ? (string) $value : json_encode($value);

                        return $this->safe(is_string($text) ? $text : '');
                    }, $row));
                }
            }

            fclose($output);
        }, sprintf('%s-%s-%s.csv', $report, $read->fromDate, $read->toDate), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
