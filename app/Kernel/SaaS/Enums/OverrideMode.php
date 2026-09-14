<?php

declare(strict_types=1);

namespace App\Kernel\SaaS\Enums;

/**
 * How a per-tenant override modifies the plan's grants.
 *
 * Revoke must be able to beat a plan grant, otherwise removing a capability
 * from one abusive center means editing the plan every other center is on.
 */
enum OverrideMode: string
{
    case Grant = 'grant';
    case Revoke = 'revoke';
}
