<?php

declare(strict_types=1);

namespace App\Kernel\Reporting;

/**
 * The database role a read is allowed to use.
 *
 * A report chooses this explicitly. In particular, Reporting never degrades
 * to Primary: an unavailable replica is an unavailable Advanced report, not a
 * reason to put analytical load on the transactional database.
 */
enum ReadTarget: string
{
    case Primary = 'primary';
    case Reporting = 'reporting';
}
