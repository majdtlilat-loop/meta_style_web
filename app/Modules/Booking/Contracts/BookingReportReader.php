<?php

declare(strict_types=1);

namespace App\Modules\Booking\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface BookingReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
