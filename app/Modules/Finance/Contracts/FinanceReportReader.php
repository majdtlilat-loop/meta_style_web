<?php

declare(strict_types=1);

namespace App\Modules\Finance\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface FinanceReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
