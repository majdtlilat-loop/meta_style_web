<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Enums;

enum ShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
