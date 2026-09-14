<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Enums;

/**
 * Long-running or failure-prone tenancy lifecycle operations worth recording.
 *
 * Deliberately a short, closed list — this is an operations log, not a
 * workflow engine.
 */
enum OperationType: string
{
    case Provision = 'provision';
    case Migrate = 'migrate';
    case Seed = 'seed';
    case Archive = 'archive';
}
