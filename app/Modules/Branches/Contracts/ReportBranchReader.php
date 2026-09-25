<?php

declare(strict_types=1);

namespace App\Modules\Branches\Contracts;

use App\Kernel\Authorization\BranchScope;
use App\Kernel\Reporting\ReadTarget;

interface ReportBranchReader
{
    /**
     * @param  list<string>  $selectedUuids
     * @return list<array{id: int, uuid: string, name: string, timezone: string}>
     */
    public function accessible(BranchScope $scope, array $selectedUuids, ReadTarget $target): array;
}
