<?php

declare(strict_types=1);

namespace App\Modules\Finance\Domain\Enums;

enum EntryDirection: string
{
    case In = 'in';
    case Out = 'out';
}
