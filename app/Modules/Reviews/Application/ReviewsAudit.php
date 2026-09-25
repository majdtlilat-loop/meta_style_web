<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Identity\Models\User;

/**
 * One way to write a reviews audit entry.
 *
 * Category `customer`: a review is a customer's words about a visit, and the
 * administrative question this answers is who MODERATED it, not what it said.
 *
 * ## What never reaches an audit row
 *
 * The capability token, in any form — not the plaintext, not the digest. The
 * customer's comment, which is theirs and already stored where it belongs. The
 * customer's name or phone; a review is identified by its own uuid
 * (docs/08-AUDIT-SECURITY.md, docs/22-REVIEWS.md §17).
 *
 * A moderation REASON is recorded: it is staff-authored, and "why was this
 * hidden" is the only interesting thing about a hidden review.
 */
final class ReviewsAudit
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
        string $targetType,
        string $targetUuid,
        array $after = [],
        array $meta = [],
        ?array $before = null,
        ?string $reason = null,
        AuditSeverity $severity = AuditSeverity::Info,
    ): void {
        $this->write($action, Actor::staff($actor), $targetType, $targetUuid, $after, $meta, $before, $reason, $severity);
    }

    /**
     * The same, for what nobody pressed a button for — an invitation minted
     * because a visit completed.
     *
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $meta
     */
    public function recordSystem(
        string $action,
        string $targetType,
        string $targetUuid,
        array $after = [],
        array $meta = [],
        AuditSeverity $severity = AuditSeverity::Info,
    ): void {
        $this->write($action, Actor::system('reviews'), $targetType, $targetUuid, $after, $meta, null, null, $severity);
    }

    /**
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $before
     */
    private function write(
        string $action,
        Actor $actor,
        string $targetType,
        string $targetUuid,
        array $after,
        array $meta,
        ?array $before,
        ?string $reason,
        AuditSeverity $severity,
    ): void {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Customer,
            actor: $actor,
            severity: $severity,
            targetType: $targetType,
            targetId: $targetUuid,
            before: $before,
            after: $after === [] ? null : $after,
            meta: $meta,
            reason: $reason,
        ));
    }
}
