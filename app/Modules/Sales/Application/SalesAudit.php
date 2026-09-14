<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Identity\Models\User;
use App\Modules\Sales\Domain\Models\Sale;

/**
 * One way to write a sales audit entry, so every one of them looks the same.
 *
 * Category `finance`. The target is the SALE's uuid — never a numeric id, never
 * a share token — and nothing about the customer beyond whether there is one:
 * an audit row is readable by more people than a customer record
 * (docs/08-AUDIT-SECURITY.md, docs/18-SALES.md §26).
 *
 * The audit log is not where sales history lives. The sale, its lines and its
 * invoice are the record; this answers "who changed what, when".
 */
final class SalesAudit
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $before
     */
    public function record(
        string $action,
        User $actor,
        Sale $sale,
        array $after = [],
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
            targetType: Sale::class,
            targetId: $sale->uuid,
            // Only when already loaded: an audit write must not cost a query.
            targetLabel: $sale->relationLoaded('invoice') ? $sale->invoice?->number : null,
            before: $before,
            after: $after === [] ? null : $after,
            meta: $meta,
            reason: $reason,
        ));
    }
}
