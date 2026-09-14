<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;

/**
 * One thing worth recording.
 *
 * `before`/`after` hold CHANGED ATTRIBUTES ONLY, never whole rows: full rows
 * bloat the table and widen the blast radius if the log itself leaks
 * (docs/08-AUDIT-SECURITY.md §3). Both are redacted on write.
 */
final readonly class AuditEvent
{
    /**
     * @param  array<array-key, mixed>|null  $before
     * @param  array<array-key, mixed>|null  $after
     * @param  array<array-key, mixed>  $meta
     */
    public function __construct(
        public string $action,
        public AuditCategory $category,
        public Actor $actor,
        public AuditSeverity $severity = AuditSeverity::Info,
        public ?string $targetType = null,
        public ?string $targetId = null,
        public ?string $targetLabel = null,
        public ?array $before = null,
        public ?array $after = null,
        public array $meta = [],
        public ?string $reason = null,
    ) {}
}
