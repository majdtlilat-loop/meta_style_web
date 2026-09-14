<?php

declare(strict_types=1);

namespace App\Kernel\Audit\Enums;

/**
 * Who performed an action (docs/08-AUDIT-SECURITY.md §3).
 *
 * Phase 2 only ever produces System and Console actors: there are no center
 * users yet. The remaining cases exist because the audit schema is written
 * once and every later phase writes into it — adding an actor type later would
 * mean rewriting stored rows.
 */
enum ActorType: string
{
    case System = 'system';
    case Console = 'console';
    case Platform = 'platform';
    case Staff = 'staff';
    case Customer = 'customer';
    case Guest = 'guest';
    case Ai = 'ai';
    case Integration = 'integration';
}
