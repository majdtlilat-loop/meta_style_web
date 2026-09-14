<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Enums;

/**
 * Per-tenant schema migration state, held in the control plane so the platform
 * can observe drift across every tenant (docs/03-DATABASE-MIGRATIONS.md §5).
 *
 * The tenant database keeps its own real `migrations` table; this is
 * observability, not a duplicate of that history.
 */
enum MigrationStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
