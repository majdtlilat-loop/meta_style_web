<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Enums;

/**
 * Where an action came in from.
 *
 * Distinct from the actor: the same person books through the web, the POS or
 * WhatsApp, and telling those apart is what makes an audit trail useful.
 */
enum AuditSource: string
{
    case Web = 'web';
    case Api = 'api';
    case Console = 'console';
    case Job = 'job';
    case System = 'system';
    case Pos = 'pos';
    case WhatsApp = 'whatsapp';
    case Ai = 'ai';
}
