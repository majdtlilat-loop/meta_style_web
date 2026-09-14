<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Enums;

enum OperationStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
