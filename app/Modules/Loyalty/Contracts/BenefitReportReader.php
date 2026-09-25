<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface BenefitReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
