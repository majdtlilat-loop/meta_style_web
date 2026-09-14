<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Enums;

enum AuditSeverity: string
{
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Critical entries are written synchronously, inside the same transaction
     * as the change. An audit entry that can be lost is not an audit entry.
     */
    public function requiresSynchronousWrite(): bool
    {
        return $this === self::Critical;
    }
}
