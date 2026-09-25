<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Enums;

/**
 * Which of a provider's systems an account talks to. The base URL of each is
 * platform configuration (`config/payments.php`), never tenant input.
 */
enum GatewayEnvironment: string
{
    case Sandbox = 'sandbox';
    case Live = 'live';
}
