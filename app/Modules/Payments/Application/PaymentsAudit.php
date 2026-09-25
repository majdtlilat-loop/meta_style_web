<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use Illuminate\Database\Eloquent\Model;

/**
 * One way to write a payments audit entry.
 *
 * Category `finance`. Targets are uuids. NEVER in a payload: credentials,
 * signatures, raw provider bodies, authorization headers, invoice share
 * secrets, payer names or account numbers (docs/19-PAYMENTS.md §49).
 *
 * The audit log is who-did-what. How much was paid, when, how and what was
 * refunded are answered by the payments and refunds themselves (§50).
 *
 * Always called inside the Action's transaction for a money transition, so the
 * record commits with the money or not at all (ADR-057).
 */
final class PaymentsAudit
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
        AuditCategory $category = AuditCategory::Finance,
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
