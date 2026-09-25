<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Enums;

/**
 * Which way points moved. Every row stores a positive number; the direction
 * says whether it added to the balance or took from it.
 */
enum PointsDirection: string
{
    case In = 'in';
    case Out = 'out';
}
