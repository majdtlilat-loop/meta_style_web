<?php

declare(strict_types=1);

namespace App\Modules\Sales\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface SalesReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
