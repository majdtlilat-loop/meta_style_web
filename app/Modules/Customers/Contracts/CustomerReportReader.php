<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface CustomerReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
