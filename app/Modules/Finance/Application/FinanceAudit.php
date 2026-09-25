<?php

declare(strict_types=1);

namespace App\Modules\Finance\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Finance audit entries. Category `finance`, targets by uuid. Who and why —
 * the amounts live on the expense, the ledger and the reconciliation, which are
 * the record (docs/20-FINANCE.md §49).
 */
final class FinanceAudit
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $before
     */
    public function record(
        string $action,
        User $actor,
        Model $target,
        string $targetUuid,
        ?array $after = null,
        array $meta = [],
        ?array $before = null,
        ?string $reason = null,
        AuditSeverity $severity = AuditSeverity::Info,
    ): void {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Finance,
            actor: Actor::staff($actor),
            severity: $severity,
            targetType: $target::class,
            targetId: $targetUuid,
            before: $before,
            after: $after,
            meta: $meta,
            reason: $reason,
        ));
    }
}
