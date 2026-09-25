<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface ReviewReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
