<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface JourneyReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
