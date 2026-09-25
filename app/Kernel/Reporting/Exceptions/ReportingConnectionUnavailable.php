<?php

declare(strict_types=1);

namespace App\Kernel\Reporting\Exceptions;

use RuntimeException;

final class ReportingConnectionUnavailable extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('The reporting connection is not configured. Advanced Reports cannot run.');
    }
}
