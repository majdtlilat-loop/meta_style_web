<?php

declare(strict_types=1);

namespace App\Modules\Packages\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use Illuminate\Database\Eloquent\Model;

/**
 * One way to write a packages audit entry, inside the transaction of the change.
 * Who did what — the session history itself is `package_transactions`.
 */
final class PackagesAudit
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $before
     */
    public function record(
        string $action,
        Actor $actor,
        Model $target,
        string $targetUuid,
        ?array $after = null,
        array $meta = [],
        ?array $before = null,
        ?string $reason = null,
        AuditSeverity $severity = AuditSeverity::Info,
        AuditCategory $category = AuditCategory::Customer,
    ): void {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: $category,
            actor: $actor,
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
