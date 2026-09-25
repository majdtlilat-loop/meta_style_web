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
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\ReportsAccess;
use App\Modules\Reports\Application\StandardReportCatalog;
use App\Modules\Reports\Application\StandardReports;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExportController extends Controller
{
    public function __invoke(
        Request $request,
        // The center host's `{center}` segment comes first; the tenant is
        // already resolved from it, so the value itself is not used.
        string $center,
        string $report,
        ReportsAccess $access,
        StandardReportCatalog $catalog,
        ReportRequestFactory $requests,
        StandardReports $reports,
        Audit $audit,
    ): StreamedResponse {
        unset($center);
        abort_if($catalog->find($report) === null, 404);
        /** @var User $user */
        $user = $request->user();
        $access->standard($user, Permission::ReportExport);

        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'uuid'],
            'source' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'max:32'],
            'employee' => ['nullable', 'uuid'],
            'service' => ['nullable', 'uuid'],
            'category' => ['nullable', 'uuid'],
        ]);
        // Each report applies only the filters its readers support
        // (StandardReportCatalog::filtersFor), exactly as the page does.
        $filters = [];

        foreach (['source', 'status', 'employee', 'service', 'category'] as $key) {
            $filters[$key] = isset($validated[$key]) ? (string) $validated[$key] : null;
        }

        $read = $requests->make(
            $user->branchScope(),
            (string) $validated['from'],
            (string) $validated['to'],
            isset($validated['branch']) ? [(string) $validated['branch']] : [],
            ReadTarget::Primary,
            $filters,
        );
        $result = $reports->run($report, $user, $read)->toArray();

        $audit->record(new AuditEvent(
            action: 'report.exported',
            category: AuditCategory::System,
            actor: Actor::staff($user),
            targetType: 'report',
            targetId: $report,
            targetLabel: (string) $result['title'],
            meta: [
                'from' => $read->fromDate,
                'to' => $read->toDate,
                'branches' => array_map(static fn ($window): string => $window->branchUuid, $read->windows),
                'filters' => $catalog->filtersFor($report, $filters),
                'as_of' => $result['as_of'],
            ],
        ));

        $filename = sprintf('%s-%s-%s.csv', $report, $read->fromDate, $read->toDate);

        return response()->streamDownload(function () use ($result): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Report', $this->safe((string) $result['title'])]);
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
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function safe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
