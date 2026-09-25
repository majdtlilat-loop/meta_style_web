<?php

declare(strict_types=1);

namespace App\Modules\Queue\Contracts;

use App\Kernel\Reporting\ReportReadRequest;

interface QueueReportReader
{
    /** @return array<string, mixed> */
    public function summary(ReportReadRequest $request): array;
}
