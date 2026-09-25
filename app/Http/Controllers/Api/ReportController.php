<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\Exceptions\ReportingConnectionUnavailable;
use App\Kernel\Reporting\ReadTarget;
use App\Modules\AdvancedReports\Application\AdvancedReportCatalog;
use App\Modules\AdvancedReports\Application\AdvancedReports;
use App\Modules\AdvancedReports\Application\AnalyzeAdvancedReport;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\StandardReportCatalog;
use App\Modules\Reports\Application\StandardReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only JSON surface for the same report applications used by the UI and
 * CSV exports. No report accepts client-calculated facts or a tenant id.
 */
final class ReportController extends Controller
{
    public function standard(
        Request $request,
        string $report,
        StandardReportCatalog $catalog,
        ReportRequestFactory $requests,
        StandardReports $reports,
    ): JsonResponse {
        abort_if($catalog->find($report) === null, 404);
        $validated = $this->filters($request, true);
        $read = $requests->make(
            $this->user($request)->branchScope(),
            $validated['from'],
            $validated['to'],
            isset($validated['branch']) ? [$validated['branch']] : [],
            ReadTarget::Primary,
            [
                'source' => $validated['source'] ?? null,
                'employee' => $validated['employee'] ?? null,
                'service' => $validated['service'] ?? null,
            ],
        );

        return ApiResponse::data(['report' => $reports->run($report, $this->user($request), $read)->toArray()]);
    }

    public function advanced(
        Request $request,
        string $report,
        AdvancedReportCatalog $catalog,
        ReportRequestFactory $requests,
        AdvancedReports $reports,
    ): JsonResponse {
        abort_if($catalog->find($report) === null, 404);
        $validated = $this->filters($request);
        try {
            $read = $requests->make(
                $this->user($request)->branchScope(),
                $validated['from'],
                $validated['to'],
                isset($validated['branch']) ? [$validated['branch']] : [],
                ReadTarget::Reporting,
            );

            return ApiResponse::data(['report' => $reports->run($report, $this->user($request), $read)->toArray()]);
        } catch (ReportingConnectionUnavailable $failure) {
            return ApiResponse::error(ApiErrorCode::ReportingUnavailable, $failure->getMessage());
        }
    }

    public function analyze(
        Request $request,
        string $report,
        AdvancedReportCatalog $catalog,
        ReportRequestFactory $requests,
        AnalyzeAdvancedReport $analyze,
    ): JsonResponse {
        abort_if($catalog->find($report) === null, 404);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'uuid'],
            'question' => ['required', 'string', 'max:1000'],
        ]);
        try {
            $read = $requests->make(
                $this->user($request)->branchScope(),
                $validated['from'],
                $validated['to'],
                isset($validated['branch']) ? [$validated['branch']] : [],
                ReadTarget::Reporting,
            );
            $answer = $analyze($report, $this->user($request), $read, $validated['question'], app()->getLocale());

            return ApiResponse::data(['analysis' => [
                'ok' => $answer->ok,
                'answer' => $answer->text,
                'failure_code' => $answer->failureCode,
            ]]);
        } catch (ReportingConnectionUnavailable $failure) {
            return ApiResponse::error(ApiErrorCode::ReportingUnavailable, $failure->getMessage());
        }
    }

    /** @return array{from: string, to: string, branch?: string, source?: string, employee?: string, service?: string} */
    private function filters(Request $request, bool $withSource = false): array
    {
        $rules = [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'uuid'],
        ];

        if ($withSource) {
            // Standard only: each report applies just the filters its
            // readers support (StandardReportCatalog::filtersFor).
            $rules['source'] = ['nullable', 'string', 'max:64'];
            $rules['employee'] = ['nullable', 'uuid'];
            $rules['service'] = ['nullable', 'uuid'];
        }

        /** @var array{from: string, to: string, branch?: string, source?: string, employee?: string, service?: string} $validated */
        $validated = $request->validate($rules);

        return $validated;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
